<?php

namespace App\Domain\Access;

/**
 * Catalogue des permissions (§3.2). Le périmètre (🔶 dans la matrice) n'est
 * pas une permission : il est appliqué en plus par les policies (RG-S02).
 */
enum Permission: string
{
    case UserManage = 'user.manage';
    case UserResetClientPassword = 'user.reset-client-password';
    case RegionView = 'region.view';
    case RegionManage = 'region.manage';
    case ClientView = 'client.view';
    case ClientManage = 'client.manage';
    case ClientBlock = 'client.block';
    case ProductView = 'product.view';
    case ProductManage = 'product.manage';
    case QuotaView = 'quota.view';
    case QuotaEdit = 'quota.edit';
    case QuotaPublish = 'quota.publish';
    case LimitView = 'limit.view';
    case LimitEdit = 'limit.edit';
    case LimitPublish = 'limit.publish';
    case OrderView = 'order.view';
    case OrderCreate = 'order.create';
    case OrderValidate = 'order.validate';
    case OrderProcess = 'order.process';
    case OrderCancelValidated = 'order.cancel-validated';
    case DayManage = 'day.manage';
    case DayExtendCutoff = 'day.extend-cutoff';
    case DashboardView = 'dashboard.view';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';
}
