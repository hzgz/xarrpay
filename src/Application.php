<?php

declare(strict_types=1);

namespace XArrPay;

use XArrPay\Http\Controllers\EpayController;
use XArrPay\Http\Controllers\HealthController;
use XArrPay\Http\Controllers\AdminController;
use XArrPay\Http\Controllers\PayController;
use XArrPay\Http\Controllers\MerchantController;
use XArrPay\Http\Request;
use XArrPay\Http\Router;

final class Application
{
    public function run(): never
    {
        $request = new Request();
        $health = new HealthController();
        $epay = new EpayController();
        $admin = new AdminController();
        $pay = new PayController();
        $merchant = new MerchantController();
        $router = new Router();

        $router->get('/api/system/timestamp', fn () => $health->timestamp());
        $router->get('/api/health', fn () => $health->health());
        $router->get('/api/config', fn () => $admin->config());
        $router->get('/api/login/connect', fn () => $admin->loginConnect());
        $router->get('/api/login/channels', fn () => $admin->loginChannels());
        $router->get('/api/login/captcha', fn () => $admin->captcha());
        $router->post('/api/login', fn (Request $request) => $merchant->login($request));
        $router->get('/api/user/profile', fn (Request $request) => $merchant->profile($request));
        $router->get('/api/staff/profile', fn (Request $request) => $admin->profile($request));
        $router->get('/api/pay-type/list', fn (Request $request) => $merchant->payTypes($request));
        $router->get('/api/channel/list', fn (Request $request) => $merchant->channels($request));
        $router->get('/api/channel/account/list', fn (Request $request) => $merchant->channelAccounts($request));
        $router->get('/api/order/list', fn (Request $request) => $merchant->orders($request));
        $router->get('/api/statistics/info', fn (Request $request) => $merchant->statistics($request));
        $router->get('/api/static/order/base', fn (Request $request) => $merchant->orderBase($request));
        $router->get('/api/static/pay-type', fn (Request $request) => $merchant->staticPayTypes($request));
        $router->get('/api/static/pay-hour-stats', fn (Request $request) => $merchant->payHourStats($request));
        $router->get('/api/static/pay-daily-stats', fn (Request $request) => $merchant->payDailyStats($request));
        $router->get('/api/notice/show', fn (Request $request) => $merchant->noticeShow($request));
        $router->get('/api/notice/shows', fn (Request $request) => $merchant->noticeShows($request));
        $router->get('/api/plugins/list', fn (Request $request) => $merchant->plugins($request));
        // The admin bundle prefixes management API calls with /api/admin when
        // admin_path is "admin". Keep this namespace explicit and separate
        // from the merchant-facing API above.
        $router->get('/api/admin/config', fn () => $admin->config());
        $router->get('/api/admin/login/connect', fn () => $admin->loginConnect());
        $router->get('/api/admin/login/captcha', fn () => $admin->captcha());
        $router->post('/api/admin/login', fn (Request $request) => $admin->login($request));
        $router->get('/api/admin/staff/profile', fn (Request $request) => $admin->profile($request));
        $router->get('/api/admin/pay-type/list', fn (Request $request) => $admin->payTypes($request));
        $router->get('/api/admin/channel/list', fn (Request $request) => $admin->channels($request));
        $router->get('/api/admin/channel/account/list', fn (Request $request) => $admin->channelAccounts($request));
        $router->get('/api/admin/order/list', fn (Request $request) => $admin->orders($request));
        $router->get('/api/admin/statistics/info', fn (Request $request) => $admin->statistics($request));
        $router->get('/api/admin/plugins/list', fn (Request $request) => $admin->plugins($request));
        $router->get('/api/admin/pay/conf', fn (Request $request) => $admin->payConf($request));
        $router->get('/api/admin/home/info', fn (Request $request) => $admin->homeInfo($request));
        $router->get('/api/admin/home/pay-distribution', fn (Request $request) => $admin->homePayDistribution($request));
        $router->get('/api/admin/home/merchant-register', fn (Request $request) => $admin->homeMerchantRegister($request));
        $router->get('/api/admin/home/order-amount', fn (Request $request) => $admin->homeOrderAmount($request));
        $router->get('/api/admin/home/daily-recharge', fn (Request $request) => $admin->homeDailyRecharge($request));
        $router->get('/api/admin/home/merchant-ranking', fn (Request $request) => $admin->homeMerchantRanking($request));
        $router->get('/api/admin/home/authorize', fn (Request $request) => $admin->homeAuthorize($request));
        $router->get('/api/admin/home/safe-rate', fn (Request $request) => $admin->homeSafeRate($request));
        $router->get('/api/admin/system/info', fn (Request $request) => $admin->systemInfo($request));
        $router->get('/api/admin/system/redis/status', fn (Request $request) => $admin->redisStatus($request));
        foreach (['/api', '/api/admin'] as $prefix) {
            $router->post($prefix . '/pay-type/create', fn (Request $request) => $admin->createPayType($request));
            $router->post($prefix . '/pay-type/edit', fn (Request $request) => $admin->editPayType($request));
            $router->post($prefix . '/pay-type/remove', fn (Request $request) => $admin->removePayType($request));
            $router->post($prefix . '/pay-type/switch-status', fn (Request $request) => $admin->switchPayTypeStatus($request));
            $router->post($prefix . '/channel/create', fn (Request $request) => $admin->createChannel($request));
            $router->post($prefix . '/channel/edit', fn (Request $request) => $admin->editChannel($request));
            $router->post($prefix . '/channel/remove', fn (Request $request) => $admin->removeChannel($request));
            $router->post($prefix . '/channel/switch-status', fn (Request $request) => $admin->switchChannelStatus($request));
            $router->get($prefix . '/channel/detail', fn (Request $request) => $admin->channelDetail($request));
            $router->post($prefix . '/channel/account/create', fn (Request $request) => $admin->createChannelAccount($request));
            $router->post($prefix . '/channel/account/edit', fn (Request $request) => $admin->editChannelAccount($request));
            $router->post($prefix . '/channel/account/remove', fn (Request $request) => $admin->removeChannelAccount($request));
            $router->post($prefix . '/channel/account/switch-status', fn (Request $request) => $admin->switchChannelAccountStatus($request));
            $router->post($prefix . '/order/remove', fn (Request $request) => $admin->removeOrder($request));
            $router->post($prefix . '/order/batch-remove', fn (Request $request) => $admin->batchRemoveOrders($request));
            $router->post($prefix . '/order/close', fn (Request $request) => $admin->closeOrder($request));
            $router->post($prefix . '/order/wait', fn (Request $request) => $admin->waitOrder($request));
            $router->post($prefix . '/order/success', fn (Request $request) => $admin->successOrder($request));
            $router->post($prefix . '/order/callback', fn (Request $request) => $admin->callbackOrder($request));
            $router->post($prefix . '/order/action', fn (Request $request) => $admin->orderAction($request));
            $router->post($prefix . '/order/create-test', fn (Request $request) => $admin->createTestOrder($request));
            $router->get($prefix . '/option/{group}', fn (Request $request, string $group) => $admin->getOptionGroup($request, $group));
            $router->post($prefix . '/option/{group}', fn (Request $request, string $group) => $admin->setOptionGroup($request, $group));
            $router->post($prefix . '/plugins/start', fn (Request $request) => $admin->pluginAction($request, 'start'));
            $router->post($prefix . '/plugins/stop', fn (Request $request) => $admin->pluginAction($request, 'stop'));
            $router->post($prefix . '/plugins/enable', fn (Request $request) => $admin->pluginAction($request, 'enable'));
            $router->post($prefix . '/plugins/disable', fn (Request $request) => $admin->pluginAction($request, 'disable'));
            $router->post($prefix . '/plugins/remove', fn (Request $request) => $admin->pluginAction($request, 'remove'));
            $router->post($prefix . '/plugins/refresh', fn (Request $request) => $admin->pluginAction($request, 'refresh'));
        }
        $router->get('/api/admin/option/compliance', fn (Request $request) => $admin->compliance($request));
        $router->post('/api/admin/option/compliance', fn (Request $request) => $admin->confirmCompliance($request));
        $router->get('/api/pay/conf', fn () => $pay->config());
        $router->post('/api/order/info', fn (Request $request) => $pay->orderInfo($request));
        $router->post('/api/order/status', fn (Request $request) => $pay->orderStatus($request));
        $router->post('/api/order/chose-type', fn (Request $request) => $pay->chooseType($request));
        $router->post('/api/order/audio', fn (Request $request) => $pay->audio($request));
        $router->post('/api/pay/types', fn (Request $request) => $pay->types($request));
        $router->post('/api/order/qrcode', fn (Request $request) => $pay->qrcode($request));
        $router->post('/api/cashier/parse', fn (Request $request) => $pay->cashierParse($request));
        $router->post('/api/order/create-cashier', fn (Request $request) => $pay->createCashier($request));
        $router->any('/xpay/epay/submit.php', fn (Request $request) => $epay->submit($request));
        $router->any('/xpay/epay/mapi.php', fn (Request $request) => $epay->mapi($request));
        $router->any('/xpay/epay/notify.php', fn (Request $request) => $epay->notify($request));
        $router->any('/xpay/epay/api.php', fn (Request $request) => $epay->api($request));
        $router->any('/api/epay/submit.php', fn (Request $request) => $epay->submit($request));
        $router->any('/api/epay/mapi.php', fn (Request $request) => $epay->mapi($request));
        $router->any('/api/epay/notify.php', fn (Request $request) => $epay->notify($request));
        $router->any('/api/epay/api.php', fn (Request $request) => $epay->api($request));
        $router->dispatch($request);
    }
}
