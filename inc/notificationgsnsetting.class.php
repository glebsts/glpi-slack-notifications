<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginGsnNotificationGsnSetting extends NotificationSetting
{
    function showFormConfig($options = [])
    {
        global $CFG_GLPI;

        $params = [
            'display' => true
        ];
        $params = array_merge($params, $options);

        $out = "<form action='" . Toolbox::getItemTypeFormURL(__CLASS__) . "' method='post'>";
        $out .= Html::hidden('config_context', ['value' => 'plugin:gsn']);
        $out .= "<div>";
        $out .= "<input type='hidden' name='id' value='1'>";
        $out .= "<table class='tab_cadre_fixe'>";
        $out .= "<tr class='tab_bg_1'><th colspan='4'>" . _n('GSN notification', 'GSN notifications', Session::getPluralNumber(), 'gsn') . "</th></tr>";
        if ($CFG_GLPI['notifications_gsn']) {
            // TODO add some config fields
            // TODO make slack bot token optional / taken from env
            // TODO add "include observers to notifications"
//            $conf = Config::getConfigurationValues('plugin:gsn');
//            $out .= "<tr><td colspan='4'>" . __('Slack bot token', 'gsn')
//                ."&nbsp;"
//                ."<input type='text' name='gsn_slack_bot_token' size='80' value='"
//                .Config::getConfigurationValues('plugin:gsn', ['gsn_slack_bot_token'])['gsn_slack_bot_token']
//                ."' />"
//                ."</td></tr>";
        } else {
            $out .= "<tr><td colspan='4'>" . __('Notifications are disabled.') . " <a href='{$CFG_GLPI['root_doc']}/front/setup.notification.php'>" . _('See configuration') . "</td></tr>";
        }
        $options['candel'] = false;
        if ($CFG_GLPI['notifications_gsn']) {
            $options['addbuttons'] = array('test_gsn_send' => __('Send a test msg from GSN to you', 'gsn'));
        }

        echo $out;

        $this->showFormButtons($options);
    }

    static public function getMode()
    {
        return Gsn::MODE_GSN;
    }

    public function getEnableLabel()
    {
        return __('Enable followups via GSN', 'gsn');
    }

    static function getTypeName($nb = 0)
    {
        return __('GSN followups configuration', 'gsn');
    }
}
