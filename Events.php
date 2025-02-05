<?php
/**
 * Keycloak Sign-In
 * @link https://github.com/cuzy-app/humhub-modules-auth-keycloak
 * @license https://github.com/cuzy-app/humhub-modules-auth-keycloak/blob/master/docs/LICENCE.md
 * @author [Marc FARRE](https://marc.fun) for [CUZY.APP](https://www.cuzy.app)
 */

namespace humhub\modules\authKeycloak;

use humhub\commands\CronController;
use humhub\compat\HForm;
use humhub\modules\admin\models\forms\UserEditForm;
use humhub\modules\authKeycloak\authclient\Keycloak;
use humhub\modules\authKeycloak\components\KeycloakApi;
use humhub\modules\authKeycloak\jobs\GroupsFullSync;
use humhub\modules\authKeycloak\jobs\GroupsUserSync;
use humhub\modules\authKeycloak\models\ConfigureForm;
use humhub\modules\membersMap\Module;
use humhub\modules\ui\menu\MenuLink;
use humhub\modules\user\authclient\Collection;
use humhub\modules\user\models\Auth;
use humhub\modules\user\models\forms\Registration;
use humhub\modules\user\models\Group;
use humhub\modules\user\models\GroupUser;
use humhub\modules\user\models\User;
use humhub\modules\user\widgets\AccountProfileMenu;
use Throwable;
use Yii;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\AfterSaveEvent;
use yii\web\Response;

class Events
{
    /**
     * @param Event $event
     * @return void
     */
    public static function onAuthClientCollectionInit($event)
    {
        /** @var Collection $authClientCollection */
        $authClientCollection = $event->sender;

        $config = new ConfigureForm();
        if ($config->enabled) {
            $authClientCollection->setClient(Keycloak::DEFAULT_NAME, [
                'class' => Keycloak::class,
                'clientId' => $config->clientId,
                'clientSecret' => $config->clientSecret,
            ]);
        }
    }


    /**
     * Adds auto login possibility
     * @param ActionEvent $event
     * @return void|\yii\console\Response|Response
     * @throws InvalidConfigException
     */
    public static function onUserAuthControllerBeforeAction(ActionEvent $event)
    {
        if (
            !Yii::$app->user->isGuest
            || Yii::$app->request->isConsoleRequest
            || $event->action->id !== 'login'
            || !Yii::$app->authClientCollection->hasClient(Keycloak::DEFAULT_NAME)
            || !Yii::$app->getModule('user')->settings->get('auth.anonymousRegistration') // if anonymous registration is not allowed and someone try to create an account, do not redirect to broker to avoid looping redirections
        ) {
            return;
        }

        $config = new ConfigureForm();
        if ($config->enabled && $config->autoLogin) {
            $event->isValid = false;
            /** @var Keycloak $authClient */
            $authClient = Yii::$app->authClientCollection->getClient(Keycloak::DEFAULT_NAME);
            return $authClient->redirectToBroker();
        }
    }


    /**
     * Adds auto login possibility
     * @param ActionEvent $event
     * @return void|\yii\console\Response|Response
     * @throws InvalidConfigException
     */
    public static function onUserRegistrationControllerBeforeAction(ActionEvent $event)
    {
        // user/registration    user/auth/login
        if (
            !Yii::$app->user->isGuest
            || Yii::$app->request->isConsoleRequest
            || $event->action->id !== 'index'
            || !Yii::$app->request->get('token') // If not invited
            || !Yii::$app->authClientCollection->hasClient(Keycloak::DEFAULT_NAME)
        ) {
            return;
        }

        $config = new ConfigureForm();
        if ($config->enabled && $config->autoLogin) {
            $event->isValid = false;
            /** @var Keycloak $authClient */
            $authClient = Yii::$app->authClientCollection->getClient(Keycloak::DEFAULT_NAME);
            return $authClient->redirectToBroker();
        }
    }


    /**
     * Registration form: hide username field
     * Admin User edit form: hide password changing
     * @param Event $event
     */
    public static function onHFormBeforeRender($event)
    {
        if (
            Yii::$app->request->isConsoleRequest
            || !Yii::$app->authClientCollection->hasClient(Keycloak::DEFAULT_NAME)
        ) {
            return;
        }

        // Registration form: hide username field
        if (
            $event->sender instanceof Registration
            && Yii::$app->controller->module->id !== 'admin'
            && !Yii::$app->request->get('token') // If invited
        ) {
            $config = new ConfigureForm();
            /** @var Registration $hform */
            $hform = $event->sender;
            $errors = $hform->getErrors();
            if (
                $config->enabled
                && $config->hideRegistrationUsernameField
                && !isset($errors['username'])
            ) {
                unset($hform->definition['elements']['User']['title']);
                $hform->definition['elements']['User']['elements']['username']['type'] = 'hidden';
            }
            return;
        }

        // Admin User edit form: hide password changing
        if (
            Yii::$app->controller->module->id === 'admin'
            && Yii::$app->controller->id === 'user'
            && Yii::$app->controller->action->id === 'edit'
            && !empty($event->sender->models['User'])
            && $event->sender->models['User'] instanceof UserEditForm
        ) {
            $config = new ConfigureForm();
            if (
                $config->enabled
                && $config->hideAdminUserEditPassword
            ) {
                /** @var HForm $hform */
                $hform = $event->sender;
                unset($hform->definition['elements']['Password']);
            }
        }
    }


    /**
     * If user email or username has changed in Humhub, update it on the broker (IdP)
     * @param AfterSaveEvent $event
     */
    public static function onModelUserAfterUpdate($event)
    {
        if (!isset($event->sender, $event->changedAttributes)) {
            return;
        }

        /** @var User $user */
        $user = $event->sender;

        // Get changed attributes
        $changedAttributes = $event->changedAttributes;

        $config = new ConfigureForm();
        if (
            array_key_exists('username', $changedAttributes)
            && $config->enabled
            && $config->updatedBrokerUsernameFromHumhubUsername
        ) {
            (new KeycloakApi())->updateUserUsername($user);
        }
        if (
            array_key_exists('email', $changedAttributes)
            && $config->enabled
            && $config->updatedBrokerEmailFromHumhubEmail
        ) {
            (new KeycloakApi())->updateUserEmail($user);
        }
    }


    /**
     * Remove session on Keycloak after logout
     * @param $event
     */
    public static function onComponentUserAfterLogout($event)
    {
        /** @var User $user */
        $user = $event->identity;

        $config = new ConfigureForm();
        if (
            $config->enabled
            && $config->removeKeycloakSessionsAfterLogout
        ) {
            (new KeycloakApi())->revokeUserSession($user->id);
        }
    }


    /**
     * @param $event
     * @return void
     */
    public static function onModelGroupAfterInsert($event)
    {
        if (
            empty($event->sender)
            || !(new ConfigureForm())->syncHumhubGroupsToKeycloak()
        ) {
            return;
        }

        /** @var Group $group */
        $group = $event->sender;

        (new KeycloakApi())->linkSameGroupNameOrCreateGroup($group->id);
    }


    /**
     * @param $event
     * @return void
     */
    public static function onModelGroupAfterDelete($event)
    {
        if (
            empty($event->sender)
            || !(new ConfigureForm())->syncHumhubGroupsToKeycloak(true)
        ) {
            return;
        }

        /** @var Group $group */
        $group = $event->sender;

        (new KeycloakApi())->removeGroup($group->keycloak_id);
    }


    /**
     * @param $event
     * @return void
     */
    public static function onModelGroupAfterUpdate($event)
    {
        if (
            empty($event->sender)
            || !isset($event->changedAttributes)
            || !(new ConfigureForm())->syncHumhubGroupsToKeycloak()
        ) {
            return;
        }

        /** @var Group $group */
        $group = $event->sender;

        // Get changed attributes
        $changedAttributes = $event->changedAttributes;

        // If name has changed
        if (array_key_exists('name', $changedAttributes)) {
            (new KeycloakApi())->renameGroup($group->id);
        }
    }


    /**
     * @param $event
     * @return void
     */
    public static function onModelGroupUserAfterInsert($event)
    {
        if (
            empty($event->sender)
            || !(new ConfigureForm())->syncHumhubGroupsToKeycloak()
        ) {
            return;
        }

        /** @var GroupUser $groupUser */
        $groupUser = $event->sender;

        $group = $groupUser->group;
        $user = $groupUser->user;
        if ($group === null || $user === null) {
            return;
        }

        (new KeycloakApi())->addUserToGroup($user->id, $group->id);
    }


    /**
     * @param $event
     * @return void
     */
    public static function onModelGroupUserAfterDelete($event)
    {
        if (
            empty($event->sender)
            || !(new ConfigureForm())->syncHumhubGroupsToKeycloak(true)
        ) {
            return;
        }

        /** @var GroupUser $groupUser */
        $groupUser = $event->sender;

        $group = $groupUser->group;
        $user = $groupUser->user;
        if ($group === null || $user === null) {
            return;
        }

        (new KeycloakApi())->deleteUserFromGroup($user->id, $group->id);
    }


    /**
     * @param $event
     * @return void
     * @throws Throwable
     */
    public static function onCronDailyRun($event)
    {
        if (!Yii::$app->getModule('auth-keycloak')) {
            return;
        }

        /** @var CronController $controller */
        $controller = $event->sender;
        $controller->stdout("Auth Keycloak module: Adding to jobs Keycloak groups synchronization with the API ");

        $config = new ConfigureForm();
        if (
            !$config->enabled
            || !$config->apiUsername
            || !$config->apiPassword
            || $config->groupsSyncMode === ConfigureForm::GROUP_SYNC_MODE_NONE
        ) {
            return;
        }

        Yii::$app->queue->push(new GroupsFullSync());
    }


    /**
     * @param $event
     * @return void
     */
    public static function onAuthAfterInsert($event)
    {
        if (
            empty($event->sender)
            || !(new ConfigureForm())->syncKeycloakGroupsToHumhub()
        ) {
            return;
        }

        /** @var Auth $auth */
        $auth = $event->sender;

        if ($auth->source === Keycloak::DEFAULT_NAME) {
            $config = new ConfigureForm();
            if (
                $config->enabled
                && $config->apiUsername
                && $config->apiPassword
                && $config->syncKeycloakGroupsToHumhub()
            ) {
                Yii::$app->queue->push(new GroupsUserSync(['userId' => $auth->user_id]));
            }
        }
    }


    /**
     * @param $event
     * @return void
     */
    public static function onAuthAfterUpdate($event)
    {
        if (
            empty($event->sender)
            || !isset($event->changedAttributes)
            || !(new ConfigureForm())->syncKeycloakGroupsToHumhub()
        ) {
            return;
        }

        /** @var Auth $auth */
        $auth = $event->sender;

        // Get changed attributes
        $changedAttributes = $event->changedAttributes;

        if (
            $auth->source === Keycloak::DEFAULT_NAME
            && array_key_exists('source', $changedAttributes)
        ) {
            $config = new ConfigureForm();
            if (
                $config->enabled
                && $config->apiUsername
                && $config->apiPassword
                && $config->syncKeycloakGroupsToHumhub()
            ) {
                Yii::$app->queue->push(new GroupsUserSync(['userId' => $auth->user_id]));
            }
        }
    }

    /**
     * @param Event $event
     * @return void
     */
    public static function onAccountProfileMenuInit(Event $event)
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('auth-keycloak');
        $settings = $module->settings;
        if (
            Yii::$app->user->isGuest
            || !$settings->get('addChangePasswordFormToAccount')
        ) {
            return;
        }

        $keycloakApi = new KeycloakApi();
        if (
            !$keycloakApi->isConnected()
            || KeycloakApi::getUserAuth(Yii::$app->user->id) === null
        ) {
            return;
        }

        /** @var AccountProfileMenu $menu */
        $menu = $event->sender;

        $menu->addEntry(new MenuLink([
            'label' => Yii::t('AuthKeycloakModule.base', 'Change password on {keycloakRealmDisplayName}', ['keycloakRealmDisplayName' => $keycloakApi->realm['displayName']]),
            'url' => ['/auth-keycloak/user/change-password'],
            'sortOrder' => 410,
            'isActive' => MenuLink::isActiveState('auth-keycloak', 'user', 'change-password'),
            'isVisible' => true,
        ]));
    }
}
