<?php

use humhub\modules\authKeycloak\Module;
use yii\db\Migration;

/**
 * Class m220316_073759_initial
 */
class m220316_073759_initial extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%group}}', 'keycloak_id', $this->string(36)->after('id'));

        // Migration to version 0.5.0
        /** @var Module $module */
        $module = Yii::$app->getModule('auth-keycloak');
        $settings = $module->settings;
        $oldApiBaseUrl = $settings->get('apiBaseUrl');
        if ($oldApiBaseUrl) {
            $posRealms = strpos($oldApiBaseUrl, '/realms/');
            $baseUrl = substr($oldApiBaseUrl, 0, $posRealms);
            $realm = substr($oldApiBaseUrl, $posRealms + 8, strpos($oldApiBaseUrl, '/protocol/') - $posRealms - 8);
            $module->settings->set('baseUrl', $baseUrl);
            $module->settings->set('realm', $realm);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m220316_073759_initial cannot be reverted.\n";

        return false;
    }
}
