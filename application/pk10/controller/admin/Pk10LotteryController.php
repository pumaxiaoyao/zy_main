<?php
/**
 * Created by PhpStorm.
 * User: fish
 * Date: 2018/3/6
 * Time: 15:58
 */

namespace app\pk10\controller\admin;


use app\pk10\model\Pk10Timelist;
use think\Request;
use app\api\model\AccMoney;
use app\api\model\AccMychg;
use app\api\model\AccUsers;
use app\pk10\model\Pk10Order;
use app\pk10\model\Pk10Lottery;
use app\pk10\model\Pk10LotteryHistory;
use app\pk10\controller\KaijiangController;
use app\auth\controller\AdminBaseController;

class Pk10LotteryController extends AdminBaseController
{
    /**
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getExpects()
    {
        $timelist = Pk10Timelist::where('draw', '<', date('H:i:s', time()))->select();
        $expects = [];
        $lty = Pk10Lottery::getLastest();
        $expect = $lty->expect;
        foreach ($timelist as $item) {
            array_push($expects, $expect);
            $expect = ($expect - 1) . "";
        }
        return $expects;
    }

    /**
     * @param Request $request
     * @return false|static[]
     * @throws \think\exception\DbException
     */
    public function history(Request $request)
    {
        $params = $request->param();
        // 页数
        $page = isset($params['page']) ? $params['page'] : 1;
        if ($page < 1) {
            $this->jsonData['status'] = 0;
            $this->jsonData['msg'] = '页码不能小于1';
            return $this->jsonData;
        }
        // 每页显示数量
        $per_page = isset($params['per_page']) ? $params['per_page'] : 15;

        // 查询条件
        $where = [];
        if (isset($params['expect'])) {
            $where['expect'] = $params['expect'];
        }
        if (isset($params['is_lottery'])) {
            $where['is_lottery'] = $params['is_lottery'];
        }
        if (isset($params['range'])) {
            $timeRange = get_time_range($params['range']);
            $where['opentimestamp'] = ['between', [$timeRange['start'], $timeRange['end']]];
            if ('today' == $params['range'] || $params['range'] == date('Y-m-d')) {
                $per_page = 180;
            }
        }
        // 开奖历史
        $list = Pk10Lottery::history($page, $per_page, $where);

        // 当前页显示的列表
        $expects = $this->getExpects();
        $kaijiangCtrl = new KaijiangController();
        foreach ($list as $key => &$item) {
            // 解析开奖内容
            $item['open_codes'] = explode(',', $item['opencode']);
            $item['details'] = $this->handleDetails($kaijiangCtrl->getPk10Result($item['opencode']));
            $res = Pk10Lottery::expectLotteried($item['expect']);
            if ($res) {
                $is_lottery = 1;
            } else {
                $is_lottery = 0;
            }
            $item['is_lottery'] = $is_lottery;
        }
        if (isset($params['range']) && ('today' == $params['range'] || $params['range'] == date('Y-m-d'))) {
            $listExpects = array_column($list->toArray(), 'expect');
            $diffExpects = array_diff($expects, $listExpects);
            $list = $list->toArray();
            foreach($diffExpects as $diffExp) {
                $item = [];
                $item['expect'] = $diffExp;
                $item['code'] = 'bjpk10';
                $item['details'] = '';
                $item['id'] = 0;
                $item['open_codes'] = [];
                $item['opencode'] = '';
                $item['opentime'] = '';
                $item['opentimestamp'] = 0;
                $item['is_lottery'] = 0;
                array_push($list, $item);
            }
            usort($list, 'cmp_expect');
            $this->jsonData['data']['list'] = $list;
        }
        $url = $request->baseUrl();
        $data = paginate_data($page, $per_page, $params, $where, $list, $url, Pk10Lottery::class, 'history');

        $this->jsonData['data'] = $data;

        return $this->jsonData;
    }

    /**
     * 加竖线分隔
     * @param $details
     * @return mixed
     */
    private function handleDetails($details)
    {
        foreach ($details as &$detail) {
            $detail = implode(' | ', $detail);
        }
        return $details;
    }

    /**
     * 手动开奖
     *
     * @param Request $request
     * @return array
     * @throws \think\exception\DbException
     */
    public function manLottery(Request $request)
    {
        $post = $request->only(['expect', 'open_codes'], 'post');
        $error = $this->validate($post, [
            'expect|期号' => 'require|number',
            'open_codes|开奖号码' => 'require|array',
        ]);
        if (true !== $error) {
            $this->jsonData['status'] = 0;
            $this->jsonData['msg'] = $error;
            return $this->jsonData;
        }
        // 获取开奖号码
        $expect = trim($post['expect']);
        $open_codes = $post['open_codes'];
        $open_code = implode(',', $open_codes);
        // 检查期数是否存在
        if (!Pk10Lottery::expectExists($expect)) {
            // 漏获取的期数行情手动写入 lottery 表
            $opentime = get_cur_date();
            $lty = new Pk10Lottery();
            $lty->code = 'bjpk10';
            $lty->expect = $expect;
            $lty->opencode = $open_code;
            $lty->opentime = $opentime;
            $lty->opentimestamp = strtotime($opentime);
            $lty->is_lottery = 0;
            $lty->save();
        }
        // 检查是否已经开奖的
        if (Pk10Lottery::expectLotteried($expect)) {
            $this->jsonData['status'] = 0;
            $this->jsonData['msg'] = "北京PK拾 [ " . $expect . " ]  已经开奖";
            return $this->jsonData;
        }
        // 开奖结果
        $result = $this->pk10Result($open_code);
        // 兑奖
        $lastest['expect'] = $expect;
        $lastest['open_code'] = $open_code;
        $msg = $this->setLucky($lastest, $result);
        // 更新开奖状态
        $lottery             = Pk10Lottery::getByExpect($expect);
        $lottery->is_lottery = 1;
        $lottery->save();

        $this->jsonData['msg'] = "北京PK拾 [ " . $expect . " ]  手动开奖成功";
        return $this->jsonData;
    }

    /**
     * 派奖
     *
     * @param $lastest
     * @param $result
     *
     * @return string
     * @throws \think\exception\DbException
     */
    private function setLucky($lastest, $result)
    {

        $expect = $lastest['expect'];
        $config = config('pk10_open_type');

        // 获取所有本期未开奖注单
        $orders     = Pk10Order::getNotOpenOrdersByExpect($expect);
        $orders_num = count($orders);

        if (empty($orders->toArray())) {
            return '[ ' . $expect . ' ] has no orders';
        }

        // 遍历注单
        $upd_orders = [];
        foreach ($orders as $order) {
            $upd_order['id'] = $order->id;
            $type            = array_search($order['mark_a'], $config);
            $bet_contents    = $result[$type];
            if (in_array($order['mark_b'], $bet_contents)) {
                $upd_order['open_ret'] = 1;
                $upd_order['open_win'] = $order['win'] - $order['money'];
                $this->setBonus($order);
            } else {
                $upd_order['open_ret'] = 0;
                $upd_order['open_win'] = 0 - $order['money'];
            }
            $upd_order['open_code']   = $lastest['opencode'];
            $upd_order['open_stu']    = 1;
            $upd_order['status']      = 1;
            $upd_order['update_time'] = time();
            unset($upd_order['create_time']);
            array_push($upd_orders, $upd_order);
            // 返水，无关是否中奖
            $this->setFs($order);
        }
        $orderModel = new Pk10Order();
        $orderModel->isUpdate()->saveAll($upd_orders);

        return "[ " . $expect . " ] $orders_num 注订单";
    }

    /**
     * @param $order
     *
     * @throws \think\exception\DbException
     */
    private function setFs($order)
    {
        $user = AccUsers::getUserByTokenint($order->tokenint);
        $this->addFs($user, $order);
        if ($user->admin) {
            $admin = AccUsers::get($user->admin);
            $this->addFs($admin, $order);
        }
        if ($user->manager) {
            $manager = AccUsers::get($user->manager);
            $this->addFs($manager, $order);
        }
        if ($user->agent) {
            $agent = AccUsers::get($user->agent);
            $this->addFs($agent, $order);
        }
    }

    /**
     * @param $user
     * @param $order
     *
     * @throws \think\exception\DbException
     */
    private function addFs($user, $order)
    {
        $userMoney = AccMoney::getMoneyByUser($user->tokenint);
        if (!$userMoney) {
            AccMoney::initMoney($user);
        }
        $chg = 0;
        switch ($user->type) {
            case 3:
                $chg = $order->fs_gv;
                break;
            case 2:
                $chg = $order->fs_mv;
                break;
            case 1:
                $chg = $order->fs_av;
                break;
            case 0:
                $chg = $order->fs_sv;
                break;
        }
        $chg_column['tokenint']     = $user->tokenint;
        $chg_column['username']     = $user->username;
        $chg_column['nickname']     = $user->nickname;
        $chg_column['c_type']       = CHG_BET_FS;
        $chg_column['c_old']        = AccMoney::getCashByUser($user->tokenint);
        $chg_column['chg']          = $chg;
        $chg_column['cur']          = $chg_column['c_old'] + $chg_column['chg'];
        $chg_column['con']          = '北京PK拾下注返水';
        $chg_column['opr_tokenint'] = '';
        $chg_column['opr_nickname'] = '';
        $chg_column['opr_username'] = '';
        $chg_column['opr_ip']       = request()->ip();
        $chg_column['opr_time']     = get_cur_date();
        $chg_column['opr_mark']     = $order->order_no;
        $chg_id                     = add_chg($chg_column);

        $chg = AccMychg::get($chg_id);
        // 更新用户余额
        upd_money($chg->tokenint, $chg->cur);
    }


    /**
     * 发放奖金
     *
     * @param $order
     *
     * @throws \think\exception\DbException
     */
    private function setBonus($order)
    {
        $user                       = AccUsers::getUserByTokenint($order->tokenint);
        $chg_column['tokenint']     = $user->tokenint;
        $chg_column['username']     = $user->username;
        $chg_column['nickname']     = $user->nickname;
        $chg_column['c_type']       = CHG_BET_LUCKY;
        $chg_column['c_old']        = AccMoney::getCashByUser($user->tokenint);
        $chg_column['chg']          = $order->win;
        $chg_column['cur']          = $chg_column['c_old'] + $chg_column['chg'];
        $chg_column['con']          = '北京PK拾中奖';
        $chg_column['opr_tokenint'] = '';
        $chg_column['opr_nickname'] = '';
        $chg_column['opr_username'] = '';
        $chg_column['opr_ip']       = request()->ip();
        $chg_column['opr_time']     = get_cur_date();
        $chg_column['opr_mark']     = '订单号：' . $order->order_no;
        $chg_id                     = add_chg($chg_column);

        $chg = AccMychg::get($chg_id);
        // 更新用户余额
        upd_money($chg->tokenint, $chg->cur);
    }

    /**
     * 暴露给外部调用
     *
     * @param $open_code
     *
     * @return array
     */
    public function getPk10Result($open_code)
    {
        return $this->pk10Result($open_code);
    }

    /**
     * 开奖结果
     *
     * @param $open_code
     *
     * @return array
     */
    private function pk10Result($open_code)
    {
        $open_codes = explode(',', $open_code);

        $ret = [];
        // 冠军
        $ret['ball_1'] = $this->generateSingleRes($open_codes[0], $open_codes[9]);

        // 亚军
        $ret['ball_2'] = $this->generateSingleRes($open_codes[1], $open_codes[8]);

        // 第三名
        $ret['ball_3'] = $this->generateSingleRes($open_codes[2], $open_codes[7]);

        // 第四名
        $ret['ball_4'] = $this->generateSingleRes($open_codes[3], $open_codes[6]);

        // 第五名
        $ret['ball_5'] = $this->generateSingleRes($open_codes[4], $open_codes[5]);

        // 第六名
        $ret['ball_6'] = $this->generateSingleRes($open_codes[5]);

        // 第七名
        $ret['ball_7'] = $this->generateSingleRes($open_codes[6]);

        // 第八名
        $ret['ball_8'] = $this->generateSingleRes($open_codes[7]);

        // 第九名
        $ret['ball_9'] = $this->generateSingleRes($open_codes[8]);

        // 第十名
        $ret['ball_10'] = $this->generateSingleRes($open_codes[9]);

        $sum = $open_codes[0] + $open_codes[1];

        // 冠亚军和
        $ret['sum'] = $this->generateSum($sum);

        return $ret;
    }

    /**
     * 计算特码的大小单双龙虎
     *
     * @param      $code
     * @param null $code2
     *
     * @return array
     */
    private function generateSingleRes($code, $code2 = null)
    {
        $ret = [];
        array_push($ret, $code);

        if ($code % 2 === 0) {
            array_push($ret, '双');
        } else {
            array_push($ret, '单');
        }

        if ($code <= 5) {
            array_push($ret, '小');
        } else {
            array_push($ret, '大');
        }

        if (!is_null($code2)) {
            if ($code > $code2) {
                array_push($ret, '龙');
            } else {
                array_push($ret, '虎');
            }
        }

        return $ret;
    }

    /**
     * 计算和的大小单双
     *
     * @param $sum
     *
     * @return array
     */
    private function generateSum($sum)
    {
        $ret = [];
        array_push($ret, $sum);

        if ($sum % 2 === 0) {
            array_push($ret, '双');
        } else {
            array_push($ret, '单');
        }

        if ($sum <= 10) {
            array_push($ret, '小');
        } else {
            array_push($ret, '大');
        }

        return $ret;
    }
}