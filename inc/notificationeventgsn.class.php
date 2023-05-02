<?php
require_once('../vendor/autoload.php');

use JoliCode\Slack\ClientFactory;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}


class PluginGsnNotificationEventGsn extends NotificationEventAbstract implements NotificationEventInterface
{
    static public function getTargetFieldName()
    {
        return 'email';
    }

    static public function getTargetField(&$data)
    {
        global $PHPLOGGER;

        $field = self::getTargetFieldName();

        if (!isset($data[$field])
            && isset($data['users_id'])) {
            // No email set: get one for user
            $PHPLOGGER->addRecord(Monolog\Logger::WARNING, __CLASS__."->getTargetField, no email found from data: ");
            $data[$field] = UserEmail::getDefaultForUser($data['users_id']);
        }

        if (empty($data[$field]) or !NotificationMailing::isUserAddressValid($data[$field])) {
            $PHPLOGGER->addRecord(Monolog\Logger::WARNING,  __CLASS__."->getTargetField, invalid or missing email: " . json_encode($data[$field]));
            $data[$field] = null;
        } else {
            $PHPLOGGER->addRecord(Monolog\Logger::WARNING,  __CLASS__."->getTargetField, target email set to: " . json_encode($data[$field]));
            $data[$field] = trim(Toolbox::strtolower($data[$field]));
        }

        return $field;
    }

    static public function canCron()
    {
        return true;
    }

    static public function getAdminData()
    {
        global $CFG_GLPI, $PHPLOGGER;
        $PHPLOGGER->addRecord(Monolog\Logger::WARNING,  __CLASS__."->getAdminData Why are we asking for admin data?");
        if (!NotificationMailing::isUserAddressValid($CFG_GLPI['admin_email'])) {
            return false;
        }

        return [
            'email' => $CFG_GLPI['admin_email'],
            'name' => $CFG_GLPI['admin_email_name'],
            'language' => $CFG_GLPI['language']
        ];
    }


    static public function getEntityAdminsData($entity)
    {
        global $DB, $CFG_GLPI, $PHPLOGGER;
        $PHPLOGGER->addRecord(Monolog\Logger::WARNING,  __CLASS__."->getEntityAdminsData Why are we asking for admin entity data?");;

        $iterator = $DB->request([
            'FROM' => 'glpi_entities',
            'WHERE' => ['id' => $entity]
        ]);

        $admins = [];

        while ($row = $iterator->next()) {
            if (NotificationMailing::isUserAddressValid($row['admin_email'])) {
                $admins[] = [
                    'language' => $CFG_GLPI['language'],
                    'email' => $row['admin_email'],
                    'name' => $row['admin_email_name']
                ];
            }
        }

        if (count($admins)) {
            return $admins;
        } else {
            return false;
        }
    }

    static public function send(array $data)
    {
        global $PHPLOGGER, $CFG_GLPI;
        $PHPLOGGER->addRecord(Monolog\Logger::WARNING,  __CLASS__."->send, data: " . getcwd() . " | " . json_encode($data));
        try {
            foreach ($data as $notificationData) {
                $current = new QueuedNotification();
                $current->getFromResultSet($notificationData);

                $client = ClientFactory::create(getenv('GSN_SLACK_BOT_TOKEN'));
                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG,  __CLASS__."->send, lookup for " . $notificationData["recipient"]);

                // This method requires your token to have the scope "users:read"
                $slackTargetUserId = $client->usersLookupByEmail([
                    'email' => $notificationData["recipient"], //self::getCurrentUserName(),
                ])->getUser()->getId();

                // This method requires your token to have the scope "chat:write"
                $slackTargetUserId = $client->chatPostMessage([
                    'username' => 'GSN',
                    'channel' => $slackTargetUserId,
                    'text' => $notificationData["body_text"],
                ]);

                Toolbox::logInFile("gsn",
                    sprintf(__('%1$s: %2$s'),
                        sprintf(__('A slack message was sent to %s'),
                            $current->fields['recipient']),
                        $current->fields['name']));
                $processed[] = $current->getID();
                $current->update(['id' => $current->fields['id'],
                    'sent_time' => $_SESSION['glpi_currenttime']]);
                $current->delete(['id' => $current->fields['id']]);

                $PHPLOGGER->addRecord(Monolog\Logger::DEBUG,  __CLASS__."->sendNotification message sent");

                return count($processed);

            }
        } catch (JoliCode\Slack\Exception\SlackErrorResponse $e) {
            Session::addMessageAfterRedirect("Error sending slack notification" . "<br/>" . $e->getMessage(), true, ERROR);

            // TODO add max retries config param
            $retries = /*$CFG_GLPI['gsn_max_retries']*/ 3 - $current->fields['sent_try'];

            $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "Failed to send the message, $retries retries left." . $e->getMessage());

            Toolbox::logInFile("gsn-error",
                sprintf(__('%1$s. Message: %2$s, Error: %3$s'),
                    sprintf(__('Warning: a slack message was undeliverable to %s with %d retries remaining'),
                        $current->fields['recipient'], $retries),
                    $current->fields['name'],
                    $e->getMessage()));

            if ($retries <= 0) {
                Toolbox::logInFile("gsn-error",
                    sprintf(__('%1$s: %2$s'),
                        sprintf(__('Fatal error: giving up delivery of slack message to %s'),
                            $current->fields['recipient']),
                        $current->fields['name']));
                // TODO maybe there is a better way
                $current->delete(['id' => $current->fields['id']]);
            }
            $input = [
                'id' => $current->fields['id'],
                'sent_try' => $current->fields['sent_try'] + 1
            ];

            // TODO add config for delay
            if ($CFG_GLPI["gsn_retry_time"] > 0) {
                $input['send_time'] = date("Y-m-d H:i:s", strtotime('+' . $CFG_GLPI["gsn_retry_time"] . ' minutes')); //Delay X minutes to try again
            }
            $current->update($input);
        }
    }
}
