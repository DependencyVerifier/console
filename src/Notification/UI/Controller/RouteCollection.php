<?php

declare(strict_types=1);

namespace Notification\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case UNSUBSCRIBE = 'unsubscribe';
    case CONFIRM_RECIPIENT = 'confirm_recipient';
    case TEAM_NOTIFICATIONS = 'team_notifications';
    case PROJECT_NOTIFICATIONS = 'project_notifications';
    case WEBHOOK_CREATE = 'webhook_create';
    case WEBHOOK_EDIT = 'webhook_edit';
    case WEBHOOK_ROTATE = 'webhook_rotate';
    case WEBHOOK_TEST = 'webhook_test';
    case WEBHOOK_DELETE = 'webhook_delete';
    case TEAM_RULE_CREATE = 'team_rule_create';
    case PROJECT_RULE_CREATE = 'project_rule_create';
    case RULE_EDIT = 'rule_edit';
    case RULE_REMOVE = 'rule_remove';
    case TEAM_SUBSCRIBE = 'team_subscribe';
    case PROJECT_SUBSCRIBE = 'project_subscribe';
    case RECIPIENT_ATTACH = 'recipient_attach';
    case RECIPIENT_DETACH = 'recipient_detach';
    case RECIPIENT_CANCEL = 'recipient_cancel';

    public function prefixed(): string
    {
        return 'notification_'.$this->value;
    }
}
