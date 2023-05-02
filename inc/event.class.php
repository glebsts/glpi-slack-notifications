<?php

class PluginGsnEvent extends CommonDBTM
{
    const TICKET = "Ticket";
    const FOLLOWUP = "ITILFollowup";
    const APPROVAL = "TicketValidation";
    const FIELDS_FROM_FALSE_UPDATE = ['date_mod', 'takeintoaccount_delay_stat'];
    const ACTION_ADD = "add";
    const ACTION_UPDATE = "update";

    static function updateTicket($ticketEvent)
    {
        global $PHPLOGGER;
        $logPrefix = __CLASS__."->updateTicket";
        $result = false;
        if (is_array($ticketEvent->updates) && count(array_diff($ticketEvent->updates, self::FIELDS_FROM_FALSE_UPDATE)) > 0) {
            $result = self::processTicketEvent($logPrefix, $ticketEvent, self::ACTION_UPDATE);
        } else {
            $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "{$logPrefix} skipping update, as it seems to be about inner entities");
        }
        return $result;
    }

    static function addTicket($ticketEvent)
    {
        return self::processTicketEvent(__CLASS__."->addTicket", $ticketEvent, self::ACTION_ADD);
    }

    static function updateFollowup(ITILFollowup $followupEvent)
    {
        return self::processFollowupEvent(__CLASS__."->updateFollowup", $followupEvent, self::ACTION_UPDATE);
    }

    static function addFollowup(ITILFollowup $followupEvent)
    {
        return self::processFollowupEvent(__CLASS__."->addFollowup", $followupEvent, self::ACTION_ADD);
    }

    static function updateApproval(TicketValidation $approvalEvent)
    {
        return self::processApprovalEvent(__CLASS__."->updateApproval", $approvalEvent, self::ACTION_UPDATE);
    }

    static function addApproval(TicketValidation $approvalEvent)
    {
        return self::processApprovalEvent(__CLASS__."->addApproval", $approvalEvent, self::ACTION_ADD);
    }

    private static function createMessageFromItem(GsnNotificationItem $item): array
    {
        $msg = array();
        $msg['itemtype'] = $item->itemType;
        $msg['items_id'] = $item->itemId;
        $msg['notificationtemplates_id'] = 0;
        $msg['entities_id'] = 0;
        $msg['sender'] = $item->updaterId;
        $msg['sendername'] = $item->updaterName;
        $msg['body_text'] = $item->toString();
        $msg['mode'] = Gsn::MODE_GSN;
        $msg['name'] = sprintf("%s %s %s", $item->action, $item->itemType, $item->ticketId);
        $msg['notificationqueueonaction'] = true;
        $msg["send_time"] = date("Y-m-d H:i:s", strtotime("-1 minutes"));
        return $msg;
    }

    static function addMessageToNotificationQueueAndSend(array $messages): bool
    {
        global $PHPLOGGER;
        $notificationQueue = new QueuedNotification();

        foreach ($messages as $message) {
            if (!$notificationQueue->add(Toolbox::addslashes_deep($message))) {
                Session::addMessageAfterRedirect(__('Error inserting GSN notification to queue', 'gsn'), true, ERROR);
                $PHPLOGGER->addRecord(Monolog\Logger::ERROR, __CLASS__."->addMessageToNotificationQueue error inserting GSN notification to queue");
                return false;
            }
            $logLine = sprintf(__('GSN notification to %s was added to queue', 'gsn'), $message['recipient']);
            Toolbox::logInFile("notification", sprintf(__('%1$s'), $logLine, ""));
            $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, __CLASS__."->addMessageToNotificationQueueAndSend->added " . sprintf(__('%1$s'),
                    $logLine));
            QueuedNotification::forceSendFor($message['itemtype'], $message['items_id']);
        }
        return true;
    }

    public static function processFollowupEvent(string $logPrefix, ITILFollowup $followupEvent, string $action)
    {
        global $PHPLOGGER;
        $ticket = new Ticket();
        if (!$ticket->getFromDB($followupEvent->fields['items_id'])) {
            $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "$logPrefix ticket for loading not found!");
            return false;
        }

        $itemId = $followupEvent->fields["id"];
        $updater = new User();
        if (!$updater->getFromDB($followupEvent->fields["users_id"])) {
            $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "$logPrefix updater not found! " . json_encode($followupEvent["users_id"]));
            return false;
        }
        $updaterName = $updater->getFriendlyName();
        $updaterId = $updater->fields["id"];

        $notificationData = [];

        // ticket users-by-type are arrays, but only single entry exist for assignee and requester
        $assigneeArr = $ticket->getUsers(CommonITILActor::ASSIGN);
        if (is_array($assigneeArr) && count($assigneeArr) > 0) {
            $assigneeId = $assigneeArr[0]["users_id"];
            if ($assigneeId != $updaterId) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding assignee $assigneeId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::FOLLOWUP, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $assigneeId, getUserName($assigneeId), CommonITILActor::ASSIGN, self::sanitizeContent($followupEvent->fields["content"])
                );
            } else {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix assignee $assigneeId is same as updater, not sending notification");
            }
        }

        $requesterArr = $ticket->getUsers(CommonITILActor::REQUESTER);
        if (is_array($requesterArr) && count($requesterArr) > 0) {
            $requesterId = $requesterArr[0]["users_id"];
            if ($requesterId != $updaterId) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding requester $requesterId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::FOLLOWUP, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $requesterId, getUserName($requesterId), CommonITILActor::REQUESTER, self::sanitizeContent($followupEvent->fields["content"])
                );
            } else {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix requester $requesterId is same as updater, not sending notification");
            }
        }

        /*   TODO   observers notification to be enabled by configuration parameter       */
        /*$observers = $ticket->getUsers(CommonITILActor::OBSERVER);
        if (is_array($observers)) {
            foreach ($observers as $observer) {
                $watcherId = $observer["users_id"];
                if ($watcherId == $updaterId) {
                    $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix watcher $watcherId is same as updater, not sending notification");
                    continue;
                }
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding observer $watcherId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::FOLLOWUP, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $watcherId, getUserName($watcherId), CommonITILActor::OBSERVER, self::sanitizeContent($followupEvent->fields["content"])
                );
            }
        }*/

        $messages = [];
        foreach ($notificationData as $notificationItem) {
            $receivingUser = new User();
            if (!$receivingUser->getFromDB($notificationItem->receiverId)) {
                $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "$logPrefix recipient user $notificationItem not found!");
                return false;
            }

            $msg = self::createMessageFromItem($notificationItem);
            $msg['recipient'] = $receivingUser->getDefaultEmail();
            $msg['recipientname'] = $receivingUser->getFriendlyName();
            $messages[] = $msg;
        }

        $queuedMessage = self::addMessageToNotificationQueueAndSend($messages);
        if ($queuedMessage != false) {
            $followupEvent->notificationqueueonaction = true;
        }
        return $queuedMessage;
    }

    public static function processApprovalEvent(string $logPrefix, TicketValidation $approvalEvent, string $action)
    {
        global $PHPLOGGER;
        $ticket = new Ticket();
        if (!$ticket->getFromDB($approvalEvent->fields['tickets_id'])) {
            $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "$logPrefix ticket for loading not found!");
            return false;
        }

        $itemId = $approvalEvent->fields["id"];

        $updater = new User();
        if (!$updater->getFromDB($approvalEvent->fields["users_id"])) {
            $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "$logPrefix updater not found! " . json_encode($approvalEvent["users_id"]));
            return false;
        }
        $updaterName = $updater->getFriendlyName();
        $updaterId = $updater->fields["id"];

        $approver = new User();
        if (!$approver->getFromDB($approvalEvent->fields["users_id_validate"])) {
            $PHPLOGGER->addRecord(Monolog\Logger::WARNING, "$logPrefix approver not found! " . json_encode($approvalEvent->fields["users_id_validate"]));
            return false;
        }
        $approverName = $approver->getFriendlyName();
        $approverId = $approver->fields["id"];

        $notificationData = [];

        // ticket users-by-type are arrays, but only single entry exist for assignee and requester
        $assigneeArr = $ticket->getUsers(CommonITILActor::ASSIGN);
        if (is_array($assigneeArr) && count($assigneeArr) > 0) {
            $assigneeId = $assigneeArr[0]["users_id"];
            if ($assigneeId != $updaterId) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding assignee $assigneeId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::APPROVAL, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $assigneeId, getUserName($assigneeId), CommonITILActor::ASSIGN, self::sanitizeContent($approvalEvent->fields["comment_submission"]),
                    $approverName,
                );
            } else {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix assignee $assigneeId is same as updater, not sending notification");
            }
        }

        $requesterArr = $ticket->getUsers(CommonITILActor::REQUESTER);
        if (is_array($requesterArr) && count($requesterArr) > 0) {
            $requesterId = $requesterArr[0]["users_id"];
            if ($requesterId != $updaterId) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding requester $requesterId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::APPROVAL, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $requesterId, getUserName($requesterId), CommonITILActor::REQUESTER, self::sanitizeContent($approvalEvent->fields["comment_submission"]),
                    $approverName,
                );
            } else {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix requester $requesterId is same as updater, not sending notification");
            }
        }

        /*   TODO   observers notification to be enabled by configuration parameter       */
        /*$observers = $ticket->getUsers(CommonITILActor::OBSERVER);
        if (is_array($observers)) {
            foreach ($observers as $observer) {
                $watcherId = $observer["users_id"];
                if ($watcherId == $updaterId) {
                    $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix watcher $watcherId is same as updater, not sending notification");
                    continue;
                }
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding observer $watcherId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::APPROVAL, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $watcherId, getUserName($watcherId), CommonITILActor::OBSERVER, self::sanitizeContent($approvalEvent->fields["comment_submission"]),
                    $approverName,
                );
            }
        }*/

        $messages = [];
        foreach ($notificationData as $notificationItem) {
            $receivingUser = new User();
            if (!$receivingUser->getFromDB($notificationItem->receiverId)) {
                $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "$logPrefix recipient user $notificationItem not found!");
                return false;
            }

            $msg = self::createMessageFromItem($notificationItem);
            $msg['recipient'] = $receivingUser->getDefaultEmail();
            $msg['recipientname'] = $receivingUser->getFriendlyName();
            $messages[] = $msg;
        }

        $queuedMessage = self::addMessageToNotificationQueueAndSend($messages);
        if ($queuedMessage != false) {
            $approvalEvent->notificationqueueonaction = true;
        }
        return $queuedMessage;
    }


    public static function processTicketEvent(string $logPrefix, $ticketEvent, $action): bool
    {
        global $PHPLOGGER;
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketEvent->fields['id'])) {
            $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix ticket for loading not found!");
            return false;
        }

        $itemId = $ticketEvent->fields["id"];

        $updater = new User();
        if (!$updater->getFromDB($ticket->fields["users_id_lastupdater"])) {
            $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix updater not found! " . json_encode($ticket->fields["users_id_lastupdater"]));
            return false;
        }
        $updaterName = $updater->getFriendlyName();
        $updaterId = $updater->fields["id"];

        $notificationData = [];

        // ticket users-by-type are arrays, but only single entry exist for assignee and requester
        $assigneeArr = $ticket->getUsers(CommonITILActor::ASSIGN);
        if (is_array($assigneeArr) && count($assigneeArr) > 0) {
            $assigneeId = $assigneeArr[0]["users_id"];
            if ($assigneeId != $updaterId) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding assignee $assigneeId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::TICKET, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $assigneeId, getUserName($assigneeId), CommonITILActor::ASSIGN, strip_tags($ticketEvent->fields["content"])
                );
            } else {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix assignee $assigneeId is same as updater, not sending notification");
            }
        }

        $requesterArr = $ticket->getUsers(CommonITILActor::REQUESTER);
        if (is_array($requesterArr) && count($requesterArr) > 0) {
            $requesterId = $requesterArr[0]["users_id"];
            if ($requesterId != $updaterId) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding requester $requesterId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::TICKET, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $requesterId, getUserName($requesterId), CommonITILActor::REQUESTER, self::sanitizeContent($ticketEvent->fields["content"])
                );
            } else {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix requester $requesterId is same as updater, not sending notification");
            }
        }

        /*   TODO   observers notification to be enabled by configuration parameter       */
        /*$observers = $ticket->getUsers(CommonITILActor::OBSERVER);
        if (is_array($observers)) {
            foreach ($observers as $observer) {
                $watcherId = $observer["users_id"];
                if ($watcherId == $updaterId) {
                    $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix watcher $watcherId is same as updater, not sending notification");
                    continue;
                }
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix adding observer $watcherId as receiver");
                $notificationData[] = new GsnNotificationItem($itemId, self::TICKET, $action,
                    $ticket->fields["id"], $ticket->fields["name"], $updaterId, $updaterName,
                    $watcherId, getUserName($watcherId), CommonITILActor::OBSERVER, self::sanitizeContent($ticketEvent->fields["content"])
                );
            }
        }*/

        $messages = [];
        foreach ($notificationData as $notificationItem) {
            $receivingUser = new User();
            if (!$receivingUser->getFromDB($notificationItem->receiverId)) {
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "$logPrefix recipient user $notificationItem->receiverId not found!");
                return false;
            }

            $msg = self::createMessageFromItem($notificationItem);
            $msg['recipient'] = $receivingUser->getDefaultEmail();
            $msg['recipientname'] = $receivingUser->getFriendlyName();
            $messages[] = $msg;
        }

        return self::addMessageToNotificationQueueAndSend($messages);
    }

    /**
     * sanitizes content string to be safe to send further as notification text
     * @param $content
     * @return string
     */
    public static function sanitizeContent($content): string
    {
        return strip_tags(html_entity_decode($content));
    }

}

/**
 * class contains logic of converting notification parameters to human-readable Slack message with formatting etc
 */
class GsnNotificationItem
{
    public $itemId;
    public $itemType;
    public $action;
    public $ticketId;
    public $ticketName;
    public $updaterId;
    public $updaterName;
    public $receiverId;
    public $receiverName;
    public $receiverRole;
    public $content;
    public $approverName;

    public function __construct($itemId,
                                $itemType,
                                $action,
                                $ticketId,
                                $ticketName,
                                $updaterId,
                                $updaterName,
                                $receiverId,
                                $receiverName,
                                $receiverRole,
                                $content,
                                $approverName = '')
    {
        $this->itemId = $itemId;
        $this->itemType = $itemType;
        $this->action = $action;
        $this->ticketId = $ticketId;
        $this->ticketName = $ticketName;
        $this->updaterId = $updaterId;
        $this->updaterName = $updaterName;
        $this->receiverId = $receiverId;
        $this->receiverName = $receiverName;
        $this->receiverRole = $receiverRole;
        $this->content = $content . "";
        $this->approverName = $approverName;
    }

    /**
     * @return string
     * returns body of notification to be sent based on notification event type and details
     */
    public function toString(): string
    {
        global $CFG_GLPI, $PHPLOGGER;
        $body = "";
        $greeting = sprintf('Hello, <%1$s/front/user.form.php?id=%2$s|%3$s>' . "\n",
            $CFG_GLPI["url_base"], $this->receiverId, $this->receiverName
        );
        $link = sprintf('`<%1$s/front/ticket.form.php?id=%2$s|#%2$s>` *<%1$s/front/ticket.form.php?id=%2$s|%3$s>*',
            $CFG_GLPI["url_base"], $this->ticketId, $this->ticketName);
        switch ($this->itemType) {
            case PluginGsnEvent::TICKET :
                switch ($this->action) {
                    case "add":
                        switch ($this->receiverRole) {
                            case CommonITILActor::REQUESTER:
                                $body = sprintf("New ticket $link was created on your behalf by %s",
                                    $this->updaterName);
                                break;
                            case CommonITILActor::ASSIGN:
                                $body = sprintf("Ticket $link assigned to you was added by %s",
                                    $this->updaterName);
                                break;
                            case CommonITILActor::OBSERVER:
                                $body = sprintf("Ticket $link you are watching was added by %s",
                                    $this->updaterName);
                                break;
                        }
                        break;
                    case "update":
                        switch ($this->receiverRole) {
                            case CommonITILActor::REQUESTER:
                                $body = sprintf("Your ticket $link was updated by %s",
                                    $this->updaterName);
                                break;
                            case CommonITILActor::ASSIGN:
                                $body = sprintf("Ticket $link assigned to you was updated by %s",
                                    $this->updaterName);
                                break;
                            case CommonITILActor::OBSERVER:
                                $body = sprintf("Ticket $link you are watching was updated by %s",
                                    $this->updaterName);
                                break;
                        }
                        break;
                    default:
                        $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "GsnNotificationItem-> UNHANDLED ticket action: " . $this->action);
                }
                break;
            case PluginGsnEvent::FOLLOWUP:
                switch ($this->action) {
                    case "add":
                        switch ($this->receiverRole) {
                            case CommonITILActor::REQUESTER:
                                $body = sprintf("New followup was added to your ticket $link by %s:\n%s",
                                    $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::ASSIGN:
                                $body = sprintf("New followup was added to ticket $link assigned by %s:\n%s",
                                    $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::OBSERVER:
                                $body = sprintf("New followup was added to ticket $link you are watching by %s:\n%s",
                                    $this->updaterName, $this->content);
                                break;
                        }
                        break;
                    case "update":
                        switch ($this->receiverRole) {
                            case CommonITILActor::REQUESTER:
                                $body = sprintf("Followup to ticket $link was updated by %s:\n%s",
                                    $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::ASSIGN:
                                $body = sprintf("Followup to ticket $link assigned to you was updated by %s:\n%s",
                                    $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::OBSERVER:
                                $body = sprintf("Followup to ticket $link you are watching was updated by %s:\n%s",
                                    $this->updaterName, $this->content);
                                break;
                        }
                        break;
                    default:
                        $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "GsnNotificationItem-> UNHANDLED followup action: " . $this->action);
                }
                break;
            case PluginGsnEvent::APPROVAL:
                switch ($this->action) {
                    case "add":
                        switch ($this->receiverRole) {
                            case CommonITILActor::REQUESTER:
                                $body = sprintf("New approval by %s was added to your ticket $link by %s:\n%s",
                                    $this->approverName, $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::ASSIGN:
                                $body = sprintf("New approval by %s was added to ticket $link assigned by %s:\n%s",
                                    $this->approverName, $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::OBSERVER:
                                $body = sprintf("New approval by %s was added to ticket $link you are watching by %s:\n%s",
                                    $this->approverName, $this->updaterName, $this->content);
                                break;
                        }
                        break;
                    case "update":
                        switch ($this->receiverRole) {
                            case CommonITILActor::REQUESTER:
                                $body = sprintf("Approval by %s for ticket $link was updated by %s:\n%s",
                                    $this->approverName, $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::ASSIGN:
                                $body = sprintf("Approval by %s for ticket $link assigned to you was updated by %s:\n%s",
                                    $this->approverName, $this->updaterName, $this->content);
                                break;
                            case CommonITILActor::OBSERVER:
                                $body = sprintf("Approval by %s for ticket $link you are watching was updated by %s:\n%s",
                                    $this->approverName, $this->updaterName, $this->content);
                                break;
                        }
                        break;
                    default:
                        $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "GsnNotificationItem-> UNHANDLED approval action: " . $this->action);
                }
                break;
            default:
                $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "GsnNotificationItem-> UNHANDLED item type: " . $this->itemType);
        }
        return $greeting . $body;
    }
}
