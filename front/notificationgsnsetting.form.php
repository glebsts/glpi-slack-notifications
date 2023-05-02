<?php
include('../../../inc/includes.php');
include('../inc/notificationgsn.php');

Session::checkRight("config", UPDATE);

if (!empty($_POST["test_gsn_send"])) {
    PluginGsnNotificationGsn::testNotification();
    Html::back();
} else if (!empty($_POST["update"])) {
    $config = new Config();

    $config->update($_POST);
    Html::back();
}

Html::header(Notification::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], "config", "notification", "config");

$notificationGsnSettingRenderer = new PluginGsnNotificationGsnSetting();
$notificationGsnSettingRenderer->display(array('id' => 1));

Html::footer();
