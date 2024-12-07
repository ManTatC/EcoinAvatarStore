<?php
/*
 * Plugin Name: EcoinAvatarStore
 * Description: 使用 Ecoins 购买头像框的商城插件，用户可通过完成任务获取 Ecoins。
 * Version: 1.0
 * Author: Your Name
 */

// 确保直接访问被禁止
if (!defined('AVIDEO_PLUGIN')) {
    die('You cannot access this file directly.');
}

class EcoinAvatarStore {
    public function __construct() {
        // 注册插件的初始化函数
        addAction('after_setup_theme', array($this, 'init'));
    }

    public function init() {
        // 创建数据库表
        $this->createDatabaseTables();

        // 注册管理后台菜单
        addAction('admin_menu', array($this, 'registerAdminMenu'));

        // 注册短代码用于显示商店页面
        add_shortcode('ecoin_avatar_store', array($this, 'displayStorePage'));

        // 注册前端脚本和样式
        addAction('head', array($this, 'enqueueStyles'));
        addAction('footer', array($this, 'enqueueScripts'));

        // 处理 AJAX 请求
        addAction('ajax_buy_avatar_frame', array($this, 'handleBuyAvatarFrame'));

        // 在用户资料页显示头像框
        addAction('user_profile_after_avatar', array($this, 'displayUserAvatarFrame'));

        // 监听用户活动以奖励 Ecoins
        addAction('video_watched', array($this, 'rewardEcoinsForWatch'));
        addAction('video_liked', array($this, 'rewardEcoinsForLike'));
        addAction('video_commented', array($this, 'rewardEcoinsForComment'));
        addAction('video_uploaded', array($this, 'rewardEcoinsForUpload'));

        // 显示新手指导清单
        addAction('header_right', array($this, 'displayBeginnerGuide'));
    }

    private function createDatabaseTables() {
        global $global;

        // 创建头像框表
        $sql = "CREATE TABLE IF NOT EXISTS `{$global['mysqli']->prefix}ecoin_avatar_frames` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `image_url` VARCHAR(255) NOT NULL,
            `price` INT NOT NULL,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        sqlDal::executeQuery($sql);

        // 创建用户 Ecoins 表
        $sql = "CREATE TABLE IF NOT EXISTS `{$global['mysqli']->prefix}ecoin_user_balance` (
            `user_id` INT NOT NULL,
            `balance` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        sqlDal::executeQuery($sql);
    }

    public function registerAdminMenu() {
        // 添加插件的管理菜单
        registerMenu(array(
            'id' => 'ecoinAvatarStoreMenu',
            'name' => 'Ecoin 头像框商城',
            'url' => 'admin.php?page=ecoin_avatar_store',
            'icon' => 'fa fa-shopping-cart',
            'access' => 'admin_access'
        ));

        // 注册管理页面
        addAction('admin_page', array($this, 'adminPage'));
    }

    public function adminPage() {
        include_once("templates/adminPage.php");
    }

    public function displayStorePage() {
        ob_start();
        include("templates/storePage.php");
        return ob_get_clean();
    }

    public function enqueueStyles() {
        echo '<link rel="stylesheet" href="' . getPluginURLPath() . 'EcoinAvatarStore/css/ecoinavatarstore.css">';
    }

    public function enqueueScripts() {
        echo '<script src="' . getPluginURLPath() . 'EcoinAvatarStore/js/ecoinavatarstore.js"></script>';
        // 提供 AJAX URL 和 nonce
        echo '<script type="text/javascript">
            var ecoinAvatarStoreAjax = {
                ajax_url: "' . getAVideoPluginURL() . 'EcoinAvatarStore/ajax_handler.php",
                nonce: "' . User::getCSRFToken() . '"
            };
        </script>';
    }

    public function handleBuyAvatarFrame() {
        global $global;

        // 验证 CSRF Token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== User::getCSRFToken()) {
            echo json_encode(array('status' => 'error', 'message' => '非法请求。'));
            exit;
        }

        // 检查用户是否登录
        if (!User::isLogged()) {
            echo json_encode(array('status' => 'error', 'message' => '请先登录。'));
            exit;
        }

        // 获取用户 ID 和 POST 数据
        $user_id = User::getId();
        $frame_id = intval($_POST['frame_id']);

        // 获取用户的 Ecoins 余额
        $user_balance = $this->getUserBalance($user_id);

        // 获取头像框信息
        $frame = sqlDal::readSql("SELECT * FROM `{$global['mysqli']->prefix}ecoin_avatar_frames` WHERE `id` = {$frame_id}");

        if (empty($frame)) {
            echo json_encode(array('status' => 'error', 'message' => '头像框不存在。'));
            exit;
        }

        $frame = $frame[0];

        // 检查用户余额
        if ($user_balance < $frame['price']) {
            echo json_encode(array('status' => 'error', 'message' => '余额不足。'));
            exit;
        }

        // 扣除 Ecoins
        $new_balance = $user_balance - $frame['price'];
        $this->setUserBalance($user_id, $new_balance);

        // 赋予用户头像框
        User::setUserAvatarFrame($user_id, $frame_id);

        echo json_encode(array('status' => 'success', 'message' => '购买成功。'));
        exit;
    }

    public function displayUserAvatarFrame($user_id) {
        global $global;

        // 获取用户的头像框
        $frame_id = $this->getUserAvatarFrame($user_id);

        if ($frame_id) {
            $frame = sqlDal::readSql("SELECT * FROM `{$global['mysqli']->prefix}ecoin_avatar_frames` WHERE `id` = {$frame_id}");

            if (!empty($frame)) {
                $frame = $frame[0];
                echo '<div class="user-avatar-frame">';
                echo '<img src="' . $frame['image_url'] . '" alt="' . htmlspecialchars($frame['name']) . '">';
                echo '</div>';
            }
        }
    }

    // 获取用户余额
    private function getUserBalance($user_id) {
        global $global;
        $balance = sqlDal::readSql("SELECT `balance` FROM `{$global['mysqli']->prefix}ecoin_user_balance` WHERE `user_id` = {$user_id}");
        if (empty($balance)) {
            // 如果用户没有余额记录，则初始化
            sqlDal::insertRow("{$global['mysqli']->prefix}ecoin_user_balance", [
                'user_id' => $user_id,
                'balance' => 0
            ]);
            return 0;
        }
        return intval($balance[0]['balance']);
    }

    // 设置用户余额
    private function setUserBalance($user_id, $balance) {
        global $global;
        sqlDal::updateRow("{$global['mysqli']->prefix}ecoin_user_balance", ['balance' => $balance], "user_id = {$user_id}");
    }

    // 获取用户头像框
    private function getUserAvatarFrame($user_id) {
        return getUserAvatarFrame($user_id); // 假设 Avideo 已有此方法
    }

    // 奖励 Ecoins 的方法
    public function rewardEcoinsForWatch($user_id, $video_id) {
        $this->addEcoins($user_id, 10);
    }

    public function rewardEcoinsForLike($user_id, $video_id) {
        $this->addEcoins($user_id, 20);
    }

    public function rewardEcoinsForComment($user_id, $video_id) {
        $this->addEcoins($user_id, 30);
    }

    public function rewardEcoinsForUpload($user_id, $video_id) {
        $this->addEcoins($user_id, 50);
    }

    // 添加 Ecoins
    private function addEcoins($user_id, $amount) {
        $current_balance = $this->getUserBalance($user_id);
        $new_balance = $current_balance + $amount;
        $this->setUserBalance($user_id, $new_balance);
    }

    // 显示新手指导清单
    public function displayBeginnerGuide() {
        include("templates/beginnerGuide.php");
    }
}

// 初始化插件
new EcoinAvatarStore();
