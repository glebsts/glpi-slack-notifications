<?php
require_once('../../../inc/includes.php');

class PluginGsnNotificationGsn implements NotificationInterface
{

    static function check($value, $options = [])
    {
        // Does nothing, but we could check if $value is actually what we expect as an email to send GSN.
        return true;
    }

    static function testNotification()
    {
        $instance = new self();
        //send a notification to current logged-in user
        $instance->sendNotification([
            '_itemtype' => 'Ticket',
            '_items_id' => 1,
            '_notificationtemplates_id' => 0,
            '_entities_id' => 0,
            'fromname' => 'GSN TEST',
            'subject' => 'Test notification',
            'content_text' => "Hello, this is a test notification.",
            'to' => PluginGsnNotificationGsn::getCurrentUserEmail(),
        ]);
        Html::back();
    }

    static function getCurrentUserEmail()
    {
        if (!isset($_SESSION["glpiID"])) {
            return "NO USER ID IN SESSION";
        }
        $user = new User();
        $user->getFromDB($_SESSION['glpiID']);
        return $user->getDefaultEmail();
    }

    function sendNotification($options = array())
    {
        global $PHPLOGGER;
        $PHPLOGGER->addRecord(Monolog\Logger::WARNING, __CLASS__."->sendNotification " . json_encode($options));
        $data = array();
        $data['itemtype'] = $options['_itemtype'];
        $data['items_id'] = $options['_items_id'];
        $data['notificationtemplates_id'] = $options['_notificationtemplates_id'];
        $data['entities_id'] = $options['_entities_id'];

        $data['sender'] = $_SESSION["glpiID"];
        $data['sendername'] = $options['fromname'];

        $data['name'] = $options['subject'];
        $data['body_text'] = $options['content_text'];
        $data['recipient'] = $options['to'];

        $data['mode'] = Gsn::MODE_GSN;

        $queue = new QueuedNotification();

        $sent = $queue->add(Toolbox::addslashes_deep($data));
        if (!$sent) {
            Session::addMessageAfterRedirect(__('Error inserting gsn notification to queue', 'gsn'), true, ERROR);
            return false;
        } else {
            //TRANS to be written in logs %1$s is the to email / %2$s is the subject of the mail
            $logLine = sprintf(__('%1$s: %2$s'), sprintf(__('GSN notification to %s was added to queue', 'gsn'), $options['to']), $options['subject']);
            Toolbox::logInFile("notification", $logLine);
            $PHPLOGGER->addRecord(Monolog\Logger::WARNING, "GSN->added " . $logLine);

        }
        $queue->sendById($sent);
        $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, __CLASS__."->sendNotification: sent");

        return true;
    }
}
