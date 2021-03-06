<?php

namespace App\Controllers;

use App\Services\Auth;
use App\Models\Node;
use App\Models\TrafficLog;
use App\Models\InviteCode;
use App\Models\Ann;
use App\Models\Speedtest;
use App\Models\Shop;
use App\Models\Coupon;
use App\Models\Bought;
use App\Models\Ticket;
use App\Services\Config;
use App\Services\MalioConfig;
use App\Services\Gateway\ChenPay;
use App\Services\BitPayment;
use App\Services\Payment;
use App\Utils;
use App\Utils\AliPay;
use App\Utils\Hash;
use App\Utils\Tools;
use App\Utils\Radius;
use App\Models\DetectLog;
use App\Models\DetectRule;
use App\Models\NodeOnlineLog;
use App\Models\NodeInfoLog;
use Ramsey\Uuid\Uuid;

use Exception;
use voku\helper\AntiXSS;

use App\Models\User;
use App\Models\Code;
use App\Models\Ip;
use App\Models\LoginIp;
use App\Models\BlockIp;
use App\Models\UnblockIp;
use App\Models\Payback;
use App\Models\Relay;
use App\Models\Token;
use App\Models\UserSubscribeLog;
use App\Utils\QQWry;
use App\Utils\GeoIP2;
use App\Utils\GA;
use App\Utils\Geetest;
use App\Utils\Telegram;
use App\Utils\TelegramSessionManager;
use App\Utils\Pay;
use App\Utils\URL;
use App\Utils\DatatablesHelper;
use App\Services\Mail;

use TelegramBot\Api\BotApi;

/**
 *  HomeController
 */
class UserController extends BaseController
{
    public function index($request, $response, $args)
    {
        $ssr_sub_token = LinkController::GenerateSSRSubCode($this->user->id, 0);

        $GtSdk = null;
        $recaptcha_sitekey = null;
        if (Config::get('enable_checkin_captcha') == true) {
            switch (Config::get('captcha_provider')) {
                case 'recaptcha':
                    $recaptcha_sitekey = Config::get('recaptcha_sitekey');
                    break;
                case 'geetest':
                    $uid = time() . random_int(1, 10000);
                    $GtSdk = Geetest::get($uid);
                    break;
            }
        }

        $Ann = Ann::orderBy('date', 'desc')->first();

        if (!$paybacks_sum = Payback::where("ref_by", $this->user->id)->sum('ref_get')) {
            $paybacks_sum = 0;
        }

        $class_left_days = floor((strtotime($this->user->class_expire)-time())/86400)+1;

        if ($_ENV['subscribe_client_url'] != '') {
            $getClient = new Token();
            for ($i = 0; $i < 10; $i++) {
                $token = $this->user->id . Tools::genRandomChar(16);
                $Elink = Token::where('token', '=', $token)->first();
                if ($Elink == null) {
                    $getClient->token = $token;
                    break;
                }
            }
            $getClient->user_id     = $this->user->id;
            $getClient->create_time = time();
            $getClient->expire_time = time() + 10 * 60;
            $getClient->save();
        } else {
            $token = '';
        }

        return $this->view()
            ->assign('class_left_days', $class_left_days)
            ->assign('paybacks_sum', $paybacks_sum)
            ->assign('subInfo', LinkController::getSubinfo($this->user, 0))
            ->assign('ssr_sub_token', $ssr_sub_token)
            ->assign('getClient', $token)
            ->assign('display_ios_class', Config::get('display_ios_class'))
            ->assign('display_ios_topup', Config::get('display_ios_topup'))
            ->assign('ios_account', Config::get('ios_account'))
            ->assign('ios_password', Config::get('ios_password'))
            ->assign('ann', $Ann)
            ->assign('geetest_html', $GtSdk)
            ->assign('mergeSub', Config::get('mergeSub'))
            ->assign('subUrl', Config::get('subUrl'))
            ->assign('user', $this->user)
            ->registerClass('URL', URL::class)
            ->assign('baseUrl', Config::get('baseUrl'))
            ->assign('recaptcha_sitekey', $recaptcha_sitekey)
            ->display('user/index.tpl');
    }

    public function lookingglass($request, $response, $args)
    {
        $Speedtest = Speedtest::where('datetime', '>', time() - Config::get('Speedtest_duration') * 3600)->orderBy('datetime', 'desc')->get();

        return $this->view()->assign('speedtest', $Speedtest)->assign('hour', Config::get('Speedtest_duration'))->display('user/lookingglass.tpl');
    }

    public function code($request, $response, $args)
    {
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $codes = Code::where('type', '<>', '-2')->where('userid', '=', $this->user->id)->orderBy('id', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        $codes->setPath('/user/code');

        $bought_pageNum = 1;
        if (isset($request->getQueryParams()["bought"])) {
            $bought_pageNum = $request->getQueryParams()["bought"];
        }
        $shops = Bought::where("userid", $this->user->id)->orderBy("id", "desc")->paginate(5, ['*'], 'bought', $bought_pageNum);
        $shops->setPath('/user/code');

        return $this->view()->assign('shops', $shops)->assign('codes', $codes)->assign('pmw', Payment::purchaseHTML())->assign('bitpay', BitPayment::purchaseHTML())->display('user/code.tpl');
    }

    public function orderDelete($request, $response, $args)
    {
        return (new ChenPay())->orderDelete($request);
    }

    public function donate($request, $response, $args)
    {
        if (Config::get('enable_donate') != true) {
            exit(0);
        }

        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $codes = Code::where(
            static function ($query) {
                $query->where('type', '=', -1)
                    ->orWhere('type', '=', -2);
            }
        )->where('isused', 1)->orderBy('id', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        $codes->setPath('/user/donate');
        return $this->view()->assign('codes', $codes)->assign('total_in', Code::where('isused', 1)->where('type', -1)->sum('number'))->assign('total_out', Code::where('isused', 1)->where('type', -2)->sum('number'))->display('user/donate.tpl');
    }

    public function isHTTPS()
    {
        define('HTTPS', false);
        if (defined('HTTPS') && HTTPS) {
            return true;
        }
        if (!isset($_SERVER)) {
            return false;
        }
        if (!isset($_SERVER['HTTPS'])) {
            return false;
        }
        if ($_SERVER['HTTPS'] === 1) {  //Apache
            return true;
        }

        if ($_SERVER['HTTPS'] === 'on') { //IIS
            return true;
        }

        if ($_SERVER['SERVER_PORT'] == 443) { //??????
            return true;
        }
        return false;
    }


    public function code_check($request, $response, $args)
    {
        $time = $request->getQueryParams()['time'];
        $codes = Code::where('userid', '=', $this->user->id)->where('usedatetime', '>', date('Y-m-d H:i:s', $time))->first();
        if ($codes != null && strpos($codes->code, '??????') !== false) {
            $res['ret'] = 1;
            return $response->getBody()->write(json_encode($res));
        }

        $res['ret'] = 0;
        return $response->getBody()->write(json_encode($res));
    }

    public function f2fpayget($request, $response, $args)
    {
        $time = $request->getQueryParams()['time'];
        $res['ret'] = 1;
        return $response->getBody()->write(json_encode($res));
    }

    public function f2fpay($request, $response, $args)
    {
        $amount = $request->getParam('amount');
        if ($amount == '') {
            $res['ret'] = 0;
            $res['msg'] = '?????????????????????' . $amount;
            return $response->getBody()->write(json_encode($res));
        }
        $user = $this->user;

        //???????????????
        $qrPayResult = Pay::alipay_get_qrcode($user, $amount, $qrPay);
        //  ?????????????????????????????????
        switch ($qrPayResult->getTradeStatus()) {
            case 'SUCCESS':
                $aliresponse = $qrPayResult->getResponse();
                $res['ret'] = 1;
                $res['msg'] = '?????????????????????';
                $res['amount'] = $amount;
                $res['qrcode'] = $qrPay->create_erweima($aliresponse->qr_code);

                break;
            case 'FAILED':
                $res['ret'] = 0;
                $res['msg'] = '????????????????????????????????????! ??????????????????????????????';

                break;
            case 'UNKNOWN':
                $res['ret'] = 0;
                $res['msg'] = '???????????????????????????! ??????????????????????????????';

                break;
            default:
                $res['ret'] = 0;
                $res['msg'] = '?????????????????????????????????! ??????????????????????????????';

                break;
        }

        return $response->getBody()->write(json_encode($res));
    }

    public function alipay($request, $response, $args)
    {
        $amount = $request->getQueryParams()['amount'];
        Pay::getGen($this->user, $amount);
    }


    public function codepost($request, $response, $args)
    {
        $code = $request->getParam('code');
        $code = trim($code);
        $user = $this->user;

        if ($code == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $codeq = Code::where('code', '=', $code)->where('isused', '=', 0)->first();
        if ($codeq == null) {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $codeq->isused = 1;
        $codeq->usedatetime = date('Y-m-d H:i:s');
        $codeq->userid = $user->id;
        $codeq->save();

        if ($codeq->type == -1) {
            $user->money += $codeq->number;
            $user->save();

            if ($user->ref_by != '' && $user->ref_by != 0 && $user->ref_by != null) {
                $gift_user = User::where('id', '=', $user->ref_by)->first();
                $gift_user->money += ($codeq->number * (Config::get('code_payback') / 100));
                $gift_user->save();

                $Payback = new Payback();
                $Payback->total = $codeq->number;
                $Payback->userid = $this->user->id;
                $Payback->ref_by = $this->user->ref_by;
                $Payback->ref_get = $codeq->number * (Config::get('code_payback') / 100);
                $Payback->datetime = time();
                $Payback->save();
            }

            $res['ret'] = 1;
            $res['msg'] = '?????????????????????????????????' . $codeq->number . '??????';

            if (Config::get('enable_donate') == true) {
                if ($this->user->is_hide == 1) {
                    Telegram::Send('?????????????????????????????????????????????????????????????????? ' . $codeq->number . ' ??????~');
                } else {
                    Telegram::Send('???????????????' . $this->user->user_name . ' ???????????????????????? ' . $codeq->number . ' ??????~');
                }
            }

            return $response->getBody()->write(json_encode($res));
        }

        if ($codeq->type == 10001) {
            $user->transfer_enable += $codeq->number * 1024 * 1024 * 1024;
            $user->save();
        }

        if ($codeq->type == 10002) {
            if (time() > strtotime($user->expire_in)) {
                $user->expire_in = date('Y-m-d H:i:s', time() + $codeq->number * 86400);
            } else {
                $user->expire_in = date('Y-m-d H:i:s', strtotime($user->expire_in) + $codeq->number * 86400);
            }
            $user->save();
        }

        if ($codeq->type >= 1 && $codeq->type <= 10000) {
            if ($user->class == 0 || $user->class != $codeq->type) {
                $user->class_expire = date('Y-m-d H:i:s', time());
                $user->save();
            }
            $user->class_expire = date('Y-m-d H:i:s', strtotime($user->class_expire) + $codeq->number * 86400);
            $user->class = $codeq->type;
            $user->save();
        }
    }


    public function GaCheck($request, $response, $args)
    {
        $code = $request->getParam('code');
        $user = $this->user;


        if ($code == '') {
            $res['ret'] = 0;
            $res['msg'] = '6????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $ga = new GA();
        $rcode = $ga->verifyCode($user->ga_token, $code);
        if (!$rcode) {
            $res['ret'] = 0;
            $res['msg'] = '???????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user->ga_enable = 1;
        $user->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????????????????';
        return $response->getBody()->write(json_encode($res));
    }


    public function GaSet($request, $response, $args)
    {
        $enable = $request->getParam('enable');
        $user = $this->user;

        if ($enable == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user->ga_enable = $enable;
        $user->save();


        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $response->getBody()->write(json_encode($res));
    }

    public function ResetPort($request, $response, $args)
    {
        $user = $this->user;
        $temp = $user->ResetPort();
        $res['msg'] = $temp['msg'];
        $res['ret'] = ($temp['ok'] === true ? 1 : 0);
        return $response->getBody()->write(json_encode($res));
    }

    public function SpecifyPort($request, $response, $args)
    {
        $user = $this->user;
        $port = $request->getParam('port');
        $temp = $user->SpecifyPort($port);
        $res['msg'] = $temp['msg'];
        $res['ret'] = ($temp['ok'] === true ? 1 : 0);
        return $response->getBody()->write(json_encode($res));
    }

    public function GaReset($request, $response, $args)
    {
        $user = $this->user;
        $ga = new GA();
        $secret = $ga->createSecret();

        $user->ga_token = $secret;
        $user->save();
        return $response->withStatus(302)->withHeader('Location', '/user/profile');
    }


    public function nodeAjax($request, $response, $args)
    {
        $id = $args['id'];
        $point_node = Node::find($id);
        $prefix = explode(' - ', $point_node->name);
        return $this->view()->assign('point_node', $point_node)->assign('prefix', $prefix[0])->assign('id', $id)->display('user/nodeajax.tpl');
    }

    public function node($request, $response, $args)
    {
        $user = Auth::getUser();
        $nodes = Node::where('type', 1)->orderBy('node_class')->orderBy('name')->get();
        $relay_rules = Relay::where('user_id', $this->user->id)->orwhere('user_id', 0)->orderBy('id', 'asc')->get();
        if (!Tools::is_protocol_relay($user)) {
            $relay_rules = array();
        }

        $array_nodes = array();
        $nodes_muport = array();
        $db = new DatatablesHelper();
        $infoLogs = $db->query('SELECT * FROM ( SELECT * FROM `ss_node_info` WHERE log_time > ' . (time() - 300) . ' ORDER BY id DESC LIMIT 999999999999 ) t GROUP BY node_id ORDER BY id DESC');
        $onlineLogs = $db->query('SELECT * FROM ( SELECT * FROM `ss_node_online_log` WHERE log_time > ' . (time() - 300) . ' ORDER BY id DESC LIMIT 999999999999 ) t GROUP BY node_id ORDER BY id DESC');

        foreach ($nodes as $node) {
            if ($user->is_admin == 0 && $node->node_group != $user->node_group && $node->node_group != 0) {
                continue;
            }
            if ($node->sort == 9) {
                $mu_user = User::where('port', '=', $node->server)->first();
                $mu_user->obfs_param = $this->user->getMuMd5();
                $nodes_muport[] = array('server' => $node, 'user' => $mu_user);
                continue;
            }
            $array_node = array();

            $array_node['id'] = $node->id;
            $array_node['class'] = $node->node_class;
            $array_node['name'] = $node->name;
            if ($node->sort == 13) {
                $server = Tools::ssv2Array($node->server);
                $array_node['server'] = $server['add'];
            } else {
                $array_node['server'] = $node->getServer();
            }
            $array_node['sort'] = $node->sort;
            $array_node['info'] = $node->info;
            $array_node['mu_only'] = $node->mu_only;
            $array_node['group'] = $node->node_group;

            $array_node['raw_node'] = $node;
            $regex = Config::get('flag_regex');
            $matches = array();
            preg_match($regex, $node->name, $matches);
            if (isset($matches[0])) {
                $array_node['flag'] = $matches[0] . '.png';
            } else {
                $array_node['flag'] = 'unknown.png';
            }

            $sort = $array_node['sort'];
            $array_node['online_user'] = 0;

            foreach ($onlineLogs as $log) {
                if ($log['node_id'] != $node->id) {
                    continue;
                }
                if (in_array($sort, array(0, 7, 8, 10, 11, 12, 13, 14))) {
                    $array_node['online_user'] = $log['online_user'];
                } else {
                    $array_node['online_user'] = -1;
                }
                break;
            }

            // check node status
            // 0: new node; -1: offline; 1: online
            $node_heartbeat = $node->node_heartbeat + 300;
            $array_node['online'] = -1;
            if (!in_array($sort, array(0, 7, 8, 10, 11, 12, 13, 14)) || $node_heartbeat == 300) {
                $array_node['online'] = 0;
            } elseif ($node_heartbeat > time()) {
                $array_node['online'] = 1;
            }

            $array_node['latest_load'] = -1;
            foreach ($infoLogs as $log) {
                if ($log['node_id'] == $node->id) {
                    $array_node['latest_load'] = (explode(' ', $log['load']))[0] * 100;
                    break;
                }
            }

            $array_node['traffic_used'] = (int) Tools::flowToGB($node->node_bandwidth);
            $array_node['traffic_limit'] = (int) Tools::flowToGB($node->node_bandwidth_limit);
            if ($node->node_speedlimit == 0.0) {
                $array_node['bandwidth'] = 0;
            } elseif ($node->node_speedlimit >= 1024.00) {
                $array_node['bandwidth'] = round($node->node_speedlimit / 1024.00, 1) . 'Gbps';
            } else {
                $array_node['bandwidth'] = $node->node_speedlimit . 'Mbps';
            }

            $array_node['traffic_rate'] = $node->traffic_rate;
            $array_node['status'] = $node->status;

            $array_nodes[] = $array_node;
        }
        return $this->view()
            ->assign('nodes', $array_nodes)
            ->assign('nodes_muport', $nodes_muport)
            ->assign('relay_rules', $relay_rules)
            ->assign('tools', new Tools())
            ->assign('user', $user)
            ->registerClass('URL', URL::class)
            ->display('user/node.tpl');
    }


    public function node_old($request, $response, $args)
    {
        $user = Auth::getUser();
        $nodes = Node::where('type', 1)->orderBy('name')->get();
        $relay_rules = Relay::where('user_id', $this->user->id)->orwhere('user_id', 0)->orderBy('id', 'asc')->get();

        if (!Tools::is_protocol_relay($user)) {
            $relay_rules = array();
        }

        $node_prefix = array();
        $node_flag_file = array();
        $node_method = array();
        $a = 0; //???????????????JB??????
        $node_order = array();
        $node_alive = array();
        $node_prealive = array();
        $node_heartbeat = array();
        $node_bandwidth = array();
        $node_muport = array();
        $node_isv6 = array();
        $node_class = array();
        $node_latestload = array();

        $ports_count = Node::where('type', 1)->where('sort', 9)->orderBy('name')->count();


        ++$ports_count;

        foreach ($nodes as $node) {
            if (($user->node_group == $node->node_group || $node->node_group == 0 || $user->is_admin) && (!$node->isNodeTrafficOut())) {
                if ($node->sort == 9) {
                    $mu_user = User::where('port', '=', $node->server)->first();
                    $mu_user->obfs_param = $this->user->getMuMd5();
                    $node_muport[] = array('server' => $node, 'user' => $mu_user);
                    continue;
                }

                $temp = explode(' - ', $node->name);
                $name_cheif = $temp[0];

                $node_isv6[$name_cheif] = $node->isv6;
                $node_class[$name_cheif] = $node->node_class;

                if (!isset($node_prefix[$name_cheif])) {
                    $node_prefix[$name_cheif] = array();
                    $node_order[$name_cheif] = $a;
                    $node_alive[$name_cheif] = 0;

                    $node_method[$name_cheif] = $temp[1] ?? '';

                    $a++;
                }


                if (in_array($node->sort, array(0, 7, 8, 10, 11, 12, 13))) {
                    $node_tempalive = $node->getOnlineUserCount();
                    $node_prealive[$node->id] = $node_tempalive;
                    if ($node->isNodeOnline() !== null) {
                        if ($node->isNodeOnline() === false) {
                            $node_heartbeat[$name_cheif] = '??????';
                        } else {
                            $node_heartbeat[$name_cheif] = '??????';
                        }
                    } elseif (!isset($node_heartbeat[$name_cheif])) {
                        $node_heartbeat[$name_cheif] = '????????????';
                    }

                    if ($node->node_bandwidth_limit == 0) {
                        $node_bandwidth[$name_cheif] = (int) ($node->node_bandwidth / 1024 / 1024 / 1024) . ' GB ??????';
                    } else {
                        $node_bandwidth[$name_cheif] = (int) ($node->node_bandwidth / 1024 / 1024 / 1024) . ' GB / ' . (int) ($node->node_bandwidth_limit / 1024 / 1024 / 1024) . ' GB - ' . $node->bandwidthlimit_resetday . ' ?????????';
                    }

                    if ($node_tempalive != '????????????') {
                        $node_alive[$name_cheif] += $node_tempalive;
                    }
                } else {
                    $node_prealive[$node->id] = '????????????';
                    if (!isset($node_heartbeat[$temp[0]])) {
                        $node_heartbeat[$name_cheif] = '????????????';
                    }
                }

                if (isset($temp[1]) && strpos($node_method[$name_cheif], $temp[1]) === false) {
                    $node_method[$name_cheif] = $node_method[$name_cheif] . ' ' . $temp[1];
                }

                $nodeLoad = $node->getNodeLoad();
                if (isset($nodeLoad[0]['load'])) {
                    $node_latestload[$name_cheif] = ((float) (explode(' ', $nodeLoad[0]['load']))[0]) * 100;
                } else {
                    $node_latestload[$name_cheif] = null;
                }

                $node_prefix[$name_cheif][] = $node;

                if (Config::get('enable_flag') == true) {
                    $regex = Config::get('flag_regex');
                    $matches = array();
                    preg_match($regex, $name_cheif, $matches);
                    $node_flag_file[$name_cheif] = $matches[0] ?? 'null';
                }
            }
        }
        $node_prefix = (object) $node_prefix;
        $node_order = (object) $node_order;
        $tools = new Tools();
        return $this->view()->assign('relay_rules', $relay_rules)->assign('node_class', $node_class)->assign('node_isv6', $node_isv6)->assign('tools', $tools)->assign('node_method', $node_method)->assign('node_muport', $node_muport)->assign('node_bandwidth', $node_bandwidth)->assign('node_heartbeat', $node_heartbeat)->assign('node_prefix', $node_prefix)->assign('node_flag_file', $node_flag_file)->assign('node_prealive', $node_prealive)->assign('node_order', $node_order)->assign('user', $user)->assign('node_alive', $node_alive)->assign('node_latestload', $node_latestload)->registerClass('URL', URL::class)->display('user/node.tpl');
    }


    public function nodeInfo($request, $response, $args)
    {
        $user = Auth::getUser();
        $id = $args['id'];
        $mu = $request->getQueryParams()['ismu'];
        $relay_rule_id = $request->getQueryParams()['relay_rule'];
        $node = Node::find($id);

        if ($node == null) {
            return null;
        }


        switch ($node->sort) {
            case 0:
                if ((($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) || $user->is_admin) && ($node->node_bandwidth_limit == 0 || $node->node_bandwidth < $node->node_bandwidth_limit)) {
                    $nodes_muport = array();
                    if($node->mu_only != -1) {
                        $nodes = Node::where('type', 1)->orderBy('node_class')->orderBy('name')->get();
                        foreach($nodes as $node_mu){
                            if ($node_mu->sort == 9) {
                                $mu_user = User::where('port', '=', $node_mu->server)->first();
                                $mu_user->obfs_param = $this->user->getMuMd5();
                                $nodes_muport[] = array('server' => $node_mu, 'user' => $mu_user);
                            }
                        }
                    }
                    return $this->view()
                        ->assign('nodes_muport', $nodes_muport)
                        ->assign('node', $node)
                        ->assign('user', $user)
                        ->assign('mu', $mu)
                        ->assign('relay_rule_id', $relay_rule_id)
                        ->registerClass('URL', URL::class)
                        ->display('user/nodeinfo.tpl');
                }
                break;
            case 1:
                if ($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) {
                    $email = $this->user->email;
                    $email = Radius::GetUserName($email);
                    $json_show = 'VPN ??????<br>?????????' . $node->server . '<br>' . '????????????' . $email . '<br>?????????' . $this->user->passwd . '<br>???????????????' . $node->method . '<br>?????????' . $node->info;

                    return $this->view()->assign('json_show', $json_show)->display('user/nodeinfovpn.tpl');
                }
                break;
            case 2:
                if ($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) {
                    $email = $this->user->email;
                    $email = Radius::GetUserName($email);
                    $json_show = 'SSH ??????<br>?????????' . $node->server . '<br>' . '????????????' . $email . '<br>?????????' . $this->user->passwd . '<br>???????????????' . $node->method . '<br>?????????' . $node->info;

                    return $this->view()->assign('json_show', $json_show)->display('user/nodeinfossh.tpl');
                }
                break;
            case 5:
                if ($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) {
                    $email = $this->user->email;
                    $email = Radius::GetUserName($email);

                    $json_show = 'Anyconnect ??????<br>?????????' . $node->server . '<br>' . '????????????' . $email . '<br>?????????' . $this->user->passwd . '<br>???????????????' . $node->method . '<br>?????????' . $node->info;

                    return $this->view()->assign('json_show', $json_show)->display('user/nodeinfoanyconnect.tpl');
                }
                break;
            case 10:
                if ((($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) || $user->is_admin) && ($node->node_bandwidth_limit == 0 || $node->node_bandwidth < $node->node_bandwidth_limit)) {
                    return $this->view()->assign('node', $node)->assign('user', $user)->assign('mu', $mu)->assign('relay_rule_id', $relay_rule_id)->registerClass('URL', URL::class)->display('user/nodeinfo.tpl');
                }
                break;
            case 13:
                if ((($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) || $user->is_admin) && ($node->node_bandwidth_limit == 0 || $node->node_bandwidth < $node->node_bandwidth_limit)) {
                    return $this->view()->assign('node', $node)->assign('user', $user)->assign('mu', $mu)->assign('relay_rule_id', $relay_rule_id)->registerClass('URL', URL::class)->display('user/nodeinfo.tpl');
                }
                break;
            case 14:
                if ((($user->class >= $node->node_class && ($user->node_group == $node->node_group || $node->node_group == 0)) || $user->is_admin) && ($node->node_bandwidth_limit == 0 || $node->node_bandwidth < $node->node_bandwidth_limit)) {
                    return $this->view()->assign('node', $node)->assign('user', $user)->registerClass('URL', URL::class)->display('user/nodeinfo.tpl');
                }
                break;
            default:
                echo '??????';
        }
    }

    public function profile($request, $response, $args)
    {
        $user = Auth::getUser();
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $paybacks = Payback::where('ref_by', $this->user->id)->orderBy('datetime', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        $paybacks->setPath('/user/profile');

        $userip = array();

        $total = Ip::where('datetime', '>=', time() - 300)->where('userid', '=', $this->user->id)->get();

        $totallogin = LoginIp::where('userid', '=', $this->user->id)->where('type', '=', 0)->orderBy('datetime', 'desc')->take(10)->get();

        $userloginip = array();

        if (MalioConfig::get('ip_database') == 'QQWry') {
            $iplocation = new QQWry();
            foreach ($totallogin as $single) {
                //if(isset($useripcount[$single->userid]))
                {
                    if (!isset($userloginip[$single->ip])) {
                        //$useripcount[$single->userid]=$useripcount[$single->userid]+1;
                        $location = $iplocation->getlocation($single->ip);
                        $userloginip[$single->ip] = iconv('gbk', 'utf-8//IGNORE', $location['country'] . $location['area']);
                    }
                }
            }

            foreach ($total as $single) {
                //if(isset($useripcount[$single->userid]))
                {
                    $single->ip = Tools::getRealIp($single->ip);
                    $is_node = Node::where('node_ip', $single->ip)->first();
                    if ($is_node) {
                        continue;
                    }


                    if (!isset($userip[$single->ip])) {
                        //$useripcount[$single->userid]=$useripcount[$single->userid]+1;
                        $location = $iplocation->getlocation($single->ip);
                        $userip[$single->ip] = iconv('gbk', 'utf-8//IGNORE', $location['country'] . $location['area']);
                    }
                }
            }
        }

        if (MalioConfig::get('ip_database') == 'GeoIP2') {
            $ip_location_geoip2 = new GeoIP2();

            foreach ($totallogin as $single) {
                {
                    if (!isset($userloginip[$single->ip])) {
                        $userloginip[$single->ip] = $ip_location_geoip2->getLocation($single->ip, $user->lang);
                    }
                }
            }

            foreach ($total as $single) {
                {
                    $single->ip = Tools::getRealIp($single->ip);
                    $is_node = Node::where('node_ip', $single->ip)->first();
                    if ($is_node) {
                        continue;
                    }

                    if (!isset($userip[$single->ip])) {
                        $userip[$single->ip] = $ip_location_geoip2->getLocation($single->ip, $user->lang);
                    }
                }
            }
        }

        $bind_token = TelegramSessionManager::add_bind_session($this->user);

        return $this->view()
            ->assign('telegram_bot', Config::get('telegram_bot'))
            ->assign('bind_token', $bind_token)
            ->assign('userip', $userip)
            ->assign('userloginip', $userloginip)
            ->assign('paybacks', $paybacks)->display('user/profile.tpl');
    }


    public function announcement($request, $response, $args)
    {
        $Anns = Ann::orderBy('date', 'desc')->get();


        return $this->view()->assign('anns', $Anns)->display('user/announcement.tpl');
    }

    public function tutorial($request, $response, $args)
    {
        $ssr_sub_token = LinkController::GenerateSSRSubCode($this->user->id, 0);
        $opts = $request->getQueryParams();
        if ($opts['os'] == 'faq') {
            return $this->view()->display('user/tutorial/faq.tpl');
        }
        if ($opts['os'] != '' && $opts['client'] != '') {
            $url = 'user/tutorial/'.$opts['os'].'-'.$opts['client'].'.tpl';
            return $this->view()
                ->assign('subInfo', LinkController::getSubinfo($this->user, 0))
                ->assign('ssr_sub_token', $ssr_sub_token)
                ->assign('mergeSub', Config::get('mergeSub'))
                ->assign('subUrl', Config::get('subUrl'))
                ->assign('user', $this->user)
                ->registerClass('URL', URL::class)
                ->assign('baseUrl', Config::get('baseUrl'))
                ->display($url);
        } else {
            return $this->view()->display('user/tutorial.tpl');
        }
    }


    public function edit($request, $response, $args)
    {
        $themes = Tools::getDir(BASE_PATH . '/resources/views');

        $BIP = BlockIp::where('ip', $_SERVER['REMOTE_ADDR'])->first();
        if ($BIP == null) {
            $Block = 'IP: ' . $_SERVER['REMOTE_ADDR'] . ' ????????????';
            $isBlock = 0;
        } else {
            $Block = 'IP: ' . $_SERVER['REMOTE_ADDR'] . ' ?????????';
            $isBlock = 1;
        }

        $bind_token = TelegramSessionManager::add_bind_session($this->user);

        $config_service = new Config();

        $ssr_sub_token = LinkController::GenerateSSRSubCode($this->user->id, 0);

        return $this->view()
            ->assign('ssr_sub_token', $ssr_sub_token)
            ->assign('user', $this->user)
            ->assign('schemes', Config::get('user_agreement_scheme'))
            ->assign('themes', $themes)
            ->assign('isBlock', $isBlock)
            ->assign('Block', $Block)
            ->assign('bind_token', $bind_token)
            ->assign('telegram_bot', Config::get('telegram_bot'))
            ->assign('config_service', $config_service)
            ->registerClass('URL', URL::class)
            ->display('user/edit.tpl');
    }


    public function invite($request, $response, $args)
    {
        $code = InviteCode::where('user_id', $this->user->id)->first();
        if ($code == null) {
            $this->user->addInviteCode();
            $code = InviteCode::where('user_id', $this->user->id)->first();
        }

        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $paybacks = Payback::where('ref_by', $this->user->id)->orderBy('id', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        if (!$paybacks_sum = Payback::where('ref_by', $this->user->id)->sum('ref_get')) {
            $paybacks_sum = 0;
        }
        $paybacks->setPath('/user/invite');

        return $this->view()->assign('code', $code)->assign('paybacks', $paybacks)->assign('paybacks_sum', $paybacks_sum)->display('user/invite.tpl');
    }

    public function buyInvite($request, $response, $args)
    {
        $price = Config::get('invite_price');
        $num = $request->getParam('num');
        $num = trim($num);

        if (!Tools::isInt($num) || $price < 0 || $num <= 0) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $amount = $price * $num;

        $user = $this->user;

        if (!$user->isLogin) {
            $res['ret'] = -1;
            return $response->getBody()->write(json_encode($res));
        }

        if ($user->money < $amount) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????' . $amount . '??????';
            return $response->getBody()->write(json_encode($res));
        }
        $user->invite_num += $num;
        $user->money -= $amount;
        $user->save();
        $res['invite_num'] = $user->invite_num;
        $res['ret'] = 1;
        $res['msg'] = '????????????????????????';
        return $response->getBody()->write(json_encode($res));
    }

    public function customInvite($request, $response, $args)
    {
        $price = Config::get('custom_invite_price');
        $customcode = $request->getParam('customcode');
        $customcode = trim($customcode);

        if (!Tools::is_validate($customcode) || $price < 0 || $customcode == '' || strlen($customcode) > 32) {
            $res['ret'] = 0;
            $res['msg'] = '????????????,???????????????????????????????????????????????????????????????32??????';
            return $response->getBody()->write(json_encode($res));
        }

        if (InviteCode::where('code', $customcode)->count() != 0) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user = $this->user;

        if (!$user->isLogin) {
            $res['ret'] = -1;
            return $response->getBody()->write(json_encode($res));
        }

        if ($user->money < $price) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????' . $price . '??????';
            return $response->getBody()->write(json_encode($res));
        }
        $code = InviteCode::where('user_id', $user->id)->first();
        $code->code = $customcode;
        $user->money -= $price;
        $user->save();
        $code->save();
        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $response->getBody()->write(json_encode($res));
    }

    public function sys()
    {
        return $this->view()->assign('ana', '')->display('user/sys.tpl');
    }

    public function updatePassword($request, $response, $args)
    {
        $oldpwd = $request->getParam('oldpwd');
        $pwd = $request->getParam('pwd');
        $repwd = $request->getParam('repwd');
        $user = $this->user;
        if (!Hash::checkPassword($user->pass, $oldpwd)) {
            $res['ret'] = 0;
            $res['msg'] = '???????????????';
            return $response->getBody()->write(json_encode($res));
        }
        if ($pwd != $repwd) {
            $res['ret'] = 0;
            $res['msg'] = '?????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if (strlen($pwd) < 8) {
            $res['ret'] = 0;
            $res['msg'] = '???????????????';
            return $response->getBody()->write(json_encode($res));
        }
        $hashPwd = Hash::passwordHash($pwd);
        $user->pass = $hashPwd;
        $user->save();

        $user->clean_link();

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }

    public function updateHide($request, $response, $args)
    {
        $hide = $request->getParam('hide');
        $user = $this->user;
        $user->is_hide = $hide;
        $user->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }

    public function Unblock($request, $response, $args)
    {
        $user = $this->user;
        $BIP = BlockIp::where('ip', $_SERVER['REMOTE_ADDR'])->get();
        foreach ($BIP as $bi) {
            $bi->delete();
        }

        $UIP = new UnblockIp();
        $UIP->userid = $user->id;
        $UIP->ip = $_SERVER['REMOTE_ADDR'];
        $UIP->datetime = time();
        $UIP->save();


        $res['ret'] = 1;
        $res['msg'] = $_SERVER['REMOTE_ADDR'];
        return $this->echoJson($response, $res);
    }

    public function shop($request, $response, $args)
    {
        $shops = Shop::where('status', 1)->orderBy('name')->get();
        return $this->view()->assign('shops', $shops)->display('user/shop.tpl');
    }

    public function CouponCheck($request, $response, $args)
    {
        $coupon = $request->getParam('coupon');
        $coupon = trim($coupon);

        $user = $this->user;

        if (!$user->isLogin) {
            $res['ret'] = -1;
            return $response->getBody()->write(json_encode($res));
        }

        $shop = $request->getParam('shop');

        $shop = Shop::where('id', $shop)->where('status', 1)->first();

        if ($shop == null) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if ($coupon == '') {
            $res['ret'] = 1;
            $res['name'] = $shop->name;
            $res['credit'] = '0 %';
            $res['total'] = $shop->price . '???';
            return $response->getBody()->write(json_encode($res));
        }

        $coupon = Coupon::where('code', $coupon)->first();

        if ($coupon == null) {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????';
            return $response->getBody()->write(json_encode($res));
        }
        if ($coupon->expire < time()) {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????';
            return $response->getBody()->write(json_encode($res));
        }
        if ($coupon->order($shop->id) == false) {
            $res['ret'] = 0;
            $res['msg'] = '?????????????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $use_limit = $coupon->onetime;
        if ($use_limit > 0) {
            $use_count = Bought::where('userid', $user->id)->where('coupon', $coupon->code)->count();
            if ($use_count >= $use_limit) {
                $res['ret'] = 0;
                $res['msg'] = '????????????????????????';
                return $response->getBody()->write(json_encode($res));
            }
        }

        $res['ret'] = 1;
        $res['name'] = $shop->name;
        $res['credit'] = $coupon->credit;
        $res['onetime'] = $coupon->onetime;
        $res['shop'] = $coupon->shop;
        $res['total'] = $shop->price * ((100 - $coupon->credit) / 100) . '???';

        return $response->getBody()->write(json_encode($res));
    }

    public function buy($request, $response, $args)
    {
        $coupon = $request->getParam('coupon');
        $coupon = trim($coupon);
        $code = $coupon;
        $shop = $request->getParam('shop');
        $disableothers = $request->getParam('disableothers');
        $autorenew = $request->getParam('autorenew');

        if (MalioConfig::get('shop_enable_trail_plan') == true && MalioConfig::get('shop_trail_plan_shopid') == $shop && $this->user->class >= 0) {
            return 0;
        };

        $shop = Shop::where('id', $shop)->where('status', 1)->first();

        if ($shop == null) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if ($coupon == '') {
            $credit = 0;
        } else {
            $coupon = Coupon::where('code', $coupon)->first();

            if ($coupon == null) {
                $credit = 0;
            } else {
                if ($coupon->onetime == 1) {
                    $onetime = true;
                }

                $credit = $coupon->credit;
            }

            if ($coupon->order($shop->id) == false) {
                $res['ret'] = 0;
                $res['msg'] = '?????????????????????????????????';
                return $response->getBody()->write(json_encode($res));
            }

            if ($coupon->expire < time()) {
                $res['ret'] = 0;
                $res['msg'] = '?????????????????????';
                return $response->getBody()->write(json_encode($res));
            }
        }

        $price = $shop->price * ((100 - $credit) / 100);
        $user = $this->user;

        if (!$user->isLogin) {
            $res['ret'] = -1;
            return $response->getBody()->write(json_encode($res));
        }

        if (bccomp($user->money, $price, 2) == -1) {
            $res['ret'] = 0;
            $res['msg'] = '?????????~ ??????????????????????????????' . $price . '??????</br><a href="/user/code">????????????????????????</a>';
            return $response->getBody()->write(json_encode($res));
        }

        $user->money = bcsub($user->money, $price, 2);
        $user->save();

        if ($disableothers == 1) {
            $boughts = Bought::where('userid', $user->id)->get();
            foreach ($boughts as $disable_bought) {
                $disable_bought->renew = 0;
                $disable_bought->save();
            }
        }

        $bought = new Bought();
        $bought->userid = $user->id;
        $bought->shopid = $shop->id;
        $bought->datetime = time();
        if ($autorenew == 0 || $shop->auto_renew == 0) {
            $bought->renew = 0;
        } else {
            $bought->renew = time() + $shop->auto_renew * 86400;
        }
        if (isset($onetime)) {
            $bought->renew = 0;
        }
        $bought->coupon = $code;
        $bought->price = $price;
        $bought->save();

        $shop->buy($user);

        $res['ret'] = 1;
        $res['msg'] = '????????????';

        return $response->getBody()->write(json_encode($res));
    }

    public function bought($request, $response, $args)
    {
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $shops = Bought::where('userid', $this->user->id)->orderBy('id', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        $shops->setPath('/user/bought');

        return $this->view()->assign('shops', $shops)->display('user/bought.tpl');
    }

    public function deleteBoughtGet($request, $response, $args)
    {
        $id = $request->getParam('id');
        $shop = Bought::where('id', $id)->where('userid', $this->user->id)->first();

        if ($shop == null) {
            $rs['ret'] = 0;
            $rs['msg'] = '?????????????????????????????????????????????';
            return $response->getBody()->write(json_encode($rs));
        }

        if ($this->user->id == $shop->userid) {
            $shop->renew = 0;
        }

        if (!$shop->save()) {
            $rs['ret'] = 0;
            $rs['msg'] = '????????????????????????';
            return $response->getBody()->write(json_encode($rs));
        }
        $rs['ret'] = 1;
        $rs['msg'] = '????????????????????????';
        return $response->getBody()->write(json_encode($rs));
    }


    public function ticket($request, $response, $args)
    {
        if (Config::get('enable_ticket') != true) {
            exit(0);
        }
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $tickets = Ticket::where('userid', $this->user->id)->where('rootid', 0)->orderBy('datetime', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        $tickets->setPath('/user/ticket');

        return $this->view()->assign('tickets', $tickets)->display('user/ticket.tpl');
    }

    public function ticket_create($request, $response, $args)
    {
        return $this->view()->display('user/ticket_create.tpl');
    }

    public function ticket_add($request, $response, $args)
    {
        $title = $request->getParam('title');
        $content = $request->getParam('content');
        $markdown = $request->getParam('markdown');

        if ($title == '' || $content == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $this->echoJson($response, $res);
        }

        if (strpos($content, 'admin') != false || strpos($content, 'user') != false) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????';
            return $this->echoJson($response, $res);
        }

        $ticket = new Ticket();
        $antiXss = new AntiXSS();

        $ticket->title = $antiXss->xss_clean($title);
        $ticket->content = $antiXss->xss_clean($content);
        $ticket->rootid = 0;
        $ticket->userid = $this->user->id;
        $ticket->datetime = time();
        $ticket->save();

        $new_ticket = Ticket::where('userid', $this->user->id)->where('title', $ticket->title)->orderBy('id','desc')->first();
        $ticket_url = Config::get('baseUrl').'/admin/ticket/'.$new_ticket->id.'/view';

        if (Config::get('mail_ticket') == true && $markdown != '') {
            $adminUser = User::where('is_admin', '=', '1')->get();
            foreach ($adminUser as $user) {
                $subject = '??????????????????';
                $to = $user->email;
                $text = '???????????????????????????';
                try {
                    Mail::send($to, $subject, 'ticket/new_ticket.tpl', [
                        'user' => $this->user, 'admin' =>$user, 'text' => $text, 'title' => $title, 'content' => $content, 'ticket_url' =>$ticket_url 
                    ], [
                    ]);
                } catch (Exception $e) {
                }
            }
        }

        /* notify admins on telegram */
        if (Config::get('enable_telegram') == true) {
            $messageText = 'Hi????????????'.PHP_EOL.'???????????????????????????'.PHP_EOL.PHP_EOL.$this->user->user_name.': '.$title.PHP_EOL.$content;
            $keyboard = new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup(
                [
                    [
                        ['text' => '????????????', 'url' => $ticket_url]
                    ]
                ]
            );
            $bot = new BotApi(Config::get('telegram_token'));
            $adminUser = User::where('is_admin', '=', '1')->get();
            foreach ($adminUser as $user) {
                if ($user->telegram_id != null) {
                    try {
                        $bot->sendMessage($user->telegram_id, $messageText, null, null, null, $keyboard);
                    } catch (Exception $e) {
                        
                    }
                }
            }
        }

        if (Config::get('useScFtqq') == true && $markdown != '') {
            $ScFtqq_SCKEY = Config::get('ScFtqq_SCKEY');
            $postdata = http_build_query(
                array(
                    'text' => Config::get('appName') . '-??????????????????',
                    'desp' => $markdown
                )
            );
            $opts = array('http' =>
            array(
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => $postdata
            ));
            $context = stream_context_create($opts);
            file_get_contents('https://sc.ftqq.com/' . $ScFtqq_SCKEY . '.send', false, $context);
        }

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }

    public function ticket_update($request, $response, $args)
    {
        $id = $args['id'];
        $content = $request->getParam('content');
        $status = $request->getParam('status');
        $markdown = $request->getParam('markdown');

        if ($content == '' || $status == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $this->echoJson($response, $res);
        }

        if (strpos($content, 'admin') != false || strpos($content, 'user') != false) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????';
            return $this->echoJson($response, $res);
        }


        $ticket_main = Ticket::where('id', '=', $id)->where('rootid', '=', 0)->first();
        if ($ticket_main->userid != $this->user->id) {
            $newResponse = $response->withStatus(302)->withHeader('Location', '/user/ticket');
            return $newResponse;
        }

        if ($status == 1 && $ticket_main->status != $status) {
            if (Config::get('mail_ticket') == true && $markdown != '') {
                $adminUser = User::where('is_admin', '=', '1')->get();
                foreach ($adminUser as $user) {
                    $subject = Config::get('appName') . '-?????????????????????';
                    $to = $user->email;
                    $text = '?????????????????????????????????<a href="' . Config::get('baseUrl') . '/admin/ticket/' . $ticket_main->id . '/view">??????</a>????????????????????????';
                    try {
                        Mail::send($to, $subject, 'news/warn.tpl', [
                            'user' => $user, 'text' => $text
                        ], []);
                    } catch (Exception $e) {
                        echo $e->getMessage();
                    }
                }
            }
            if (Config::get('useScFtqq') == true && $markdown != '') {
                $ScFtqq_SCKEY = Config::get('ScFtqq_SCKEY');
                $postdata = http_build_query(
                    array(
                        'text' => Config::get('appName') . '-?????????????????????',
                        'desp' => $markdown
                    )
                );
                $opts = array('http' =>
                array(
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $postdata
                ));
                $context = stream_context_create($opts);
                file_get_contents('https://sc.ftqq.com/' . $ScFtqq_SCKEY . '.send', false, $context);
                $useScFtqq = Config::get('ScFtqq_SCKEY');
            }
        } else {
            if (Config::get('mail_ticket') == true && $markdown != '') {
                $adminUser = User::where('is_admin', '=', '1')->get();
                foreach ($adminUser as $user) {
                    $subject = Config::get('appName') . '-???????????????';
                    $to = $user->email;
                    $text = '???????????????????????????<a href="' . Config::get('baseUrl') . '/admin/ticket/' . $ticket_main->id . '/view">??????</a>????????????????????????';
                    try {
                        Mail::send($to, $subject, 'news/warn.tpl', [
                            'user' => $user, 'text' => $text
                        ], []);
                    } catch (Exception $e) {
                        echo $e->getMessage();
                    }
                }
            }
            if (Config::get('enable_telegram') == 'true') {
                $messageText = 'Hi????????????'.PHP_EOL.'??????????????????????????????????????????'.PHP_EOL.PHP_EOL.$this->user->user_name.': '.$ticket_main->title.PHP_EOL.$content;
                $ticket_url = Config::get('baseUrl') . '/admin/ticket/' . $ticket_main->id . '/view';
                $keyboard = new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup(
                    [
                        [
                            ['text' => '????????????', 'url' => $ticket_url]
                        ]
                    ]
                );
                $bot = new BotApi(Config::get('telegram_token'));
                $adminUser = User::where('is_admin', '=', '1')->get();
                foreach ($adminUser as $user) {
                    if ($user->telegram_id != null) {
                        try {
                            $bot->sendMessage($user->telegram_id, $messageText, null, null, null, $keyboard);
                        } catch (Exception $e) {
                        }
                    }
                }
            }
            if (Config::get('useScFtqq') == true && $markdown != '') {
                $ScFtqq_SCKEY = Config::get('ScFtqq_SCKEY');
                $postdata = http_build_query(
                    array(
                        'text' => Config::get('appName') . '-???????????????',
                        'desp' => $markdown
                    )
                );
                $opts = array('http' =>
                array(
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $postdata
                ));
                $context = stream_context_create($opts);
                file_get_contents('https://sc.ftqq.com/' . $ScFtqq_SCKEY . '.send', false, $context);
            }
        }
        

        $antiXss = new AntiXSS();

        $ticket = new Ticket();
        $ticket->title = $antiXss->xss_clean($ticket_main->title);
        $ticket->content = $antiXss->xss_clean($content);
        $ticket->rootid = $ticket_main->id;
        $ticket->userid = $this->user->id;
        $ticket->datetime = time();
        $ticket_main->status = $status;

        $ticket_main->save();
        $ticket->save();


        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }

    public function ticket_view($request, $response, $args)
    {
        $id = $args['id'];
        $ticket_main = Ticket::where('id', '=', $id)->where('rootid', '=', 0)->first();
        if ($ticket_main->userid != $this->user->id) {
            $newResponse = $response->withStatus(302)->withHeader('Location', '/user/ticket');
            return $newResponse;
        }

        $pageNum = $request->getQueryParams()['page'] ?? 1;


        $ticketset = Ticket::where('id', $id)->orWhere('rootid', '=', $id)->orderBy('datetime', 'desc')->paginate(5, ['*'], 'page', $pageNum);
        $ticketset->setPath('/user/ticket/' . $id . '/view');


        return $this->view()->assign('ticketset', $ticketset)->assign('ticket_status', $ticket_main->status)->assign('id', $id)->display('user/ticket_view.tpl');
    }


    public function updateWechat($request, $response, $args)
    {
        $type = $request->getParam('imtype');
        $wechat = $request->getParam('wechat');
        $wechat = trim($wechat);

        $user = $this->user;

        if ($user->telegram_id != 0) {
            $res['ret'] = 0;
            $res['msg'] = '???????????? Telegram ????????????????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if ($wechat == '' || $type == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user1 = User::where('im_value', $wechat)->where('im_type', $type)->first();
        if ($user1 != null) {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user->im_type = $type;
        $antiXss = new AntiXSS();
        $user->im_value = $antiXss->xss_clean($wechat);
        $user->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }

    public function updateSSR($request, $response, $args)
    {
        $protocol = $request->getParam('protocol');
        $obfs = $request->getParam('obfs');
        $obfs_param = $request->getParam('obfs_param');
        $obfs_param = trim($obfs_param);

        $user = $this->user;

        if ($obfs == '' || $protocol == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if (!Tools::is_param_validate('obfs', $obfs)) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if (!Tools::is_param_validate('protocol', $protocol)) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $antiXss = new AntiXSS();

        $user->protocol = $antiXss->xss_clean($protocol);
        $user->obfs = $antiXss->xss_clean($obfs);
        $user->obfs_param = $antiXss->xss_clean($obfs_param);

        if (!Tools::checkNoneProtocol($user)) {
            $res['ret'] = 0;
            $res['msg'] = '?????????????????????????????????????????? none ??????????????????????????????????????????????????????<br>' . implode(',', Config::getSupportParam('allow_none_protocol')) . '<br>????????????????????????????????????????????????????????????????????????';
            return $this->echoJson($response, $res);
        }

        if (!URL::SSCanConnect($user) && !URL::SSRCanConnect($user)) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????';
            return $this->echoJson($response, $res);
        }

        $user->save();

        $user->cleanSubCache();

        if (!URL::SSCanConnect($user)) {
            $res['ret'] = 1;
            $res['msg'] = '??????????????????????????????????????????????????????????????????????????? Shadowsocks??????????????????????????????????????????????????? ShadowsocksR ????????????';
            return $this->echoJson($response, $res);
        }

        if (!URL::SSRCanConnect($user)) {
            $res['ret'] = 1;
            $res['msg'] = '??????????????????????????????????????????????????????????????????????????? ShadowsocksR ????????????????????????????????????????????? Shadowsocks ????????????';
            return $this->echoJson($response, $res);
        }

        $res['ret'] = 1;
        $res['msg'] = '??????????????????????????????????????????????????????';
        return $this->echoJson($response, $res);
    }

    public function updateTheme($request, $response, $args)
    {
        $theme = $request->getParam('theme');

        $user = $this->user;

        if ($theme == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }


        $user->theme = filter_var($theme, FILTER_SANITIZE_STRING);
        $user->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }


    public function updateMail($request, $response, $args)
    {
        $mail = $request->getParam('mail');
        $mail = trim($mail);
        $user = $this->user;

        if (!($mail == '1' || $mail == '0')) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }


        $user->sendDailyMail = $mail;
        $user->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }

    public function PacSet($request, $response, $args)
    {
        $pac = $request->getParam('pac');

        $user = $this->user;

        if ($pac == '') {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????';
            return $response->getBody()->write(json_encode($res));
        }


        $user->pac = $pac;
        $user->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????';
        return $this->echoJson($response, $res);
    }


    public function updateSsPwd($request, $response, $args)
    {
        $user = Auth::getUser();
        $pwd = $request->getParam('sspwd');
        $pwd = trim($pwd);
        $current_timestamp = time();
        $new_uuid = Uuid::uuid3(Uuid::NAMESPACE_DNS, 
        $user->email . '|' . $current_timestamp);
        $otheruuid = User::where('uuid', $new_uuid)->first();

        if ($pwd == '') {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????';
            return $response->getBody()->write(json_encode($res));
        }
        if (!Tools::is_validate($pwd)) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }
        if ($otheruuid != null) {
            $res['ret'] = 0;
            $res['msg'] = '??????????????????????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }
        
        $user->uuid = $new_uuid;
        $user->save();
        $user->updateSsPwd($pwd);
        $res['ret'] = 1;


        Radius::Add($user, $pwd);

        $user->cleanSubCache();

        return $this->echoJson($response, $res);
    }

    public function updateMethod($request, $response, $args)
    {
        $user = Auth::getUser();
        $method = $request->getParam('method');
        $method = strtolower($method);

        if ($method == '') {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        if (!Tools::is_param_validate('method', $method)) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user->method = $method;

        if (!Tools::checkNoneProtocol($user)) {
            $res['ret'] = 0;
            $res['msg'] = '???????????????????????????????????????????????? none ???????????????????????????????????????<br>' . implode(',', Config::getSupportParam('allow_none_protocol')) . '<br>??????????????????????????????????????????????????????????????????';
            return $this->echoJson($response, $res);
        }

        if (!URL::SSCanConnect($user) && !URL::SSRCanConnect($user)) {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????';
            return $this->echoJson($response, $res);
        }

        $user->updateMethod($method);

        if (!URL::SSCanConnect($user)) {
            $res['ret'] = 1;
            $res['msg'] = '??????????????????????????????????????????????????????????????????????????? Shadowsocks??????????????????????????????????????????????????? ShadowsocksR ????????????';
            return $this->echoJson($response, $res);
        }

        if (!URL::SSRCanConnect($user)) {
            $res['ret'] = 1;
            $res['msg'] = '??????????????????????????????????????????????????????????????????????????? ShadowsocksR ????????????????????????????????????????????? Shadowsocks ????????????';
            return $this->echoJson($response, $res);
        }

        $res['ret'] = 1;
        $res['msg'] = '??????????????????????????????????????????????????????????????????';
        return $this->echoJson($response, $res);
    }

    public function logout($request, $response, $args)
    {
        Auth::logout();
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function doCheckIn($request, $response, $args)
    {
        if (Config::get('enable_checkin_captcha') == true) {
            switch (Config::get('captcha_provider')) {
                case 'recaptcha':
                    $recaptcha = $request->getParam('recaptcha');
                    if ($recaptcha == '') {
                        $ret = false;
                    } else {
                        $json = file_get_contents('https://recaptcha.net/recaptcha/api/siteverify?secret=' . Config::get('recaptcha_secret') . '&response=' . $recaptcha);
                        $ret = json_decode($json)->success;
                    }
                    break;
                case 'geetest':
                    $ret = Geetest::verify($request->getParam('geetest_challenge'), $request->getParam('geetest_validate'), $request->getParam('geetest_seccode'));
                    break;
            }
            if (!$ret) {
                $res['ret'] = 0;
                $res['msg'] = '??????????????????????????????????????????????????????????????????';
                return $response->getBody()->write(json_encode($res));
            }
        }

        if (strtotime($this->user->expire_in) < time()) {
            $res['ret'] = 0;
            $res['msg'] = '???????????????????????????????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $checkin = $this->user->checkin();
        if ($checkin['ok'] === false) {
            $res['ret'] = 0;
            $res['msg'] = $checkin['msg'];
            return $this->echoJson($response, $res);
        }
        if (MalioConfig::get('daily_bonus_mode') == 'malio') {
            $traffic = random_int(MalioConfig::get('daily_bonus_settings')[$this->user->class]['min'], MalioConfig::get('daily_bonus_settings')[$this->user->class]['max']);
        } else {
            $traffic = random_int(Config::get('checkinMin'), Config::get('checkinMax'));
        }
        $this->user->transfer_enable += Tools::toMB($traffic);
        $this->user->last_check_in_time = time();
        $this->user->save();
        $res['msg'] = $this->i18n->get('got-daily-bonus',[$traffic]);
        $res['ret'] = 1;
        return $this->echoJson($response, $res);
    }

    public function kill($request, $response, $args)
    {
        return $this->view()->display('user/kill.tpl');
    }

    public function handleKill($request, $response, $args)
    {
        $user = Auth::getUser();

        $email = $user->email;

        $passwd = $request->getParam('passwd');
        // check passwd
        $res = array();
        if (!Hash::checkPassword($user->pass, $passwd)) {
            $res['ret'] = 0;
            $res['msg'] = ' ????????????';
            return $this->echoJson($response, $res);
        }

        if (Config::get('enable_kill') == true) {
            Auth::logout();
            $user->cleanSubCache();
            $user->kill_user();
            $res['ret'] = 1;
            $res['msg'] = '??????????????????????????????????????????????????????????????????!';
        } else {
            $res['ret'] = 0;
            $res['msg'] = '????????????????????????????????????????????????????????????';
        }
        return $this->echoJson($response, $res);
    }

    public function trafficLog($request, $response, $args)
    {
        $traffic = TrafficLog::where('user_id', $this->user->id)->where('log_time', '>', time() - 3 * 86400)->orderBy('id', 'desc')->get();
        return $this->view()->assign('logs', $traffic)->display('user/trafficlog.tpl');
    }


    public function detect_index($request, $response, $args)
    {
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $logs = DetectRule::paginate(15, ['*'], 'page', $pageNum);
        $logs->setPath('/user/detect');
        return $this->view()->assign('rules', $logs)->display('user/detect_index.tpl');
    }

    public function detect_log($request, $response, $args)
    {
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $logs = DetectLog::orderBy('id', 'desc')->where('user_id', $this->user->id)->paginate(15, ['*'], 'page', $pageNum);
        $logs->setPath('/user/detect/log');
        return $this->view()->assign('logs', $logs)->display('user/detect_log.tpl');
    }

    public function disable($request, $response, $args)
    {
        return $this->view()->display('user/disable.tpl');
    }

    public function telegram_reset($request, $response, $args)
    {
        $user = $this->user;
        $user->TelegramReset();
        return $response->withStatus(302)->withHeader('Location', '/user/edit');
    }

    public function resetURL($request, $response, $args)
    {
        $user = $this->user;
        $user->clean_link();
        $user->cleanSubCache();
        return $response->withStatus(302)->withHeader('Location', '/user');
    }

    public function resetInviteURL($request, $response, $args)
    {
        $user = $this->user;
        $user->clear_inviteCodes();
        return $response->withStatus(302)->withHeader('Location', '/user/invite');
    }

    public function backtoadmin($request, $response, $args)
    {
        $userid = Utils\Cookie::get('uid');
        $adminid = Utils\Cookie::get('old_uid');
        $user = User::find($userid);
        $admin = User::find($adminid);

        if (!$admin->is_admin || !$user) {
            Utils\Cookie::set([
                'uid' => null,
                'email' => null,
                'key' => null,
                'ip' => null,
                'expire_in' => null,
                'old_uid' => null,
                'old_email' => null,
                'old_key' => null,
                'old_ip' => null,
                'old_expire_in' => null,
                'old_local' => null
            ], time() - 1000);
        }
        $expire_in = Utils\Cookie::get('old_expire_in');
        $local = Utils\Cookie::get('old_local');
        Utils\Cookie::set([
            'uid' => Utils\Cookie::get('old_uid'),
            'email' => Utils\Cookie::get('old_email'),
            'key' => Utils\Cookie::get('old_key'),
            'ip' => Utils\Cookie::get('old_ip'),
            'expire_in' => $expire_in,
            'old_uid' => null,
            'old_email' => null,
            'old_key' => null,
            'old_ip' => null,
            'old_expire_in' => null,
            'old_local' => null
        ], $expire_in);
        return $response->withStatus(302)->withHeader('Location', $local);
    }

    public function switchType($request, $response, $args)
    {
        $user = $this->user;

        $type = $request->getParam('id');
        $type = trim($type);

        $scheme = [];
        $items = Config::get('user_agreement_scheme');
        foreach ($items as $item) {
            if ($type == $item['id']) {
                $scheme = $item;
            }
        }
        if (!isset($scheme['id'])) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $user->method = $scheme['method'];
        $user->protocol = $scheme['protocol'];
        $user->obfs = $scheme['obfs'];
        $user->save();

        $user->cleanSubCache();

        $res['ret'] = 1;
        $res['msg'] = '??????' . $scheme['name'] . '??????';

        return $this->echoJson($response, $res);
    }

    public function getUserAllURL($request, $response, $args)
    {
        $user = $this->user;
        $type = $request->getQueryParams()["type"];
        $return = '';
        switch ($type) {
            case 'ss':
                $return .= URL::getAllUrl($user, 0, 1) . PHP_EOL;
                break;
            case 'ssr':
                $return .= URL::getAllUrl($user, 0, 0) . PHP_EOL;
                break;
            case 'ssd':
                $return .= LinkController::getSSD($user, 1, [], ['type' => 'ss', 'is_mu' => 1], false) . PHP_EOL;
                break;
            case 'v2ray':
                $return .= URL::getAllVMessUrl($user) . PHP_EOL;
                break;
            default:
                $return .= '???????????????';
                break;
        }
        $newResponse = $response->withHeader('Content-type', ' application/octet-stream; charset=utf-8')->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')->withHeader('Content-Disposition', ' attachment; filename=node.txt');
        $newResponse->write($return);

        return $newResponse;
    }

    public function subscribe_log($request, $response, $args)
    {
        $pageNum = $request->getQueryParams()['page'] ?? 1;
        $logs = UserSubscribeLog::orderBy('id', 'desc')->where('user_id', $this->user->id)->paginate(15, ['*'], 'page', $pageNum);
        $logs->setPath('/user/subscribe_log');

        $iplocation = new QQWry();

        return $this->view()->assign('logs', $logs)->assign('iplocation', $iplocation)->display('user/subscribe_log.tpl');
    }

    public function getmoney($request, $response, $args)
    {
        $user = $this->user;
        $res['money'] = $user->money;
        $res['ret'] = 1;
        return $this->echoJson($response, $res);
    }
    
    public function getPlanInfo($request, $response, $args)
    {
        $plan_time = $request->getQueryParams()['time'];
        $plan_num = $request->getQueryParams()['num'];
        if (empty($plan_num) || empty($plan_time)) {
            $res['ret'] = 0;
            return $this->echoJson($response, $res); 
        }
        $shop_id = (MalioConfig::get('plan_shop_id'))[$plan_num][$plan_time];
        $shop = Shop::where('id', $shop_id)->first();
        $res['id'] = $shop_id;
        $res['name'] = $shop->name;
        $res['price'] = $shop->price;
        $res['ret'] = 1;
        return $this->echoJson($response, $res);
    }

    public function getPlanTime($request, $response, $args)
    {
        $plan_num = $request->getQueryParams()['num'];
        if (empty($plan_num)) {
            $res['ret'] = 0;
            return $this->echoJson($response, $res); 
        }
        $plan_time = [];
        foreach ((MalioConfig::get('plan_shop_id'))[$plan_num] as $key => $value) {
            if ($value != 0) {
                $plan_time[] = $key;
            }
        }
        $res['plan_time'] = $plan_time;
        $res['ret'] = 1;
        return $this->echoJson($response, $res);
    }

    public function buyTrafficPackage($request, $response, $args)
    {
        $shopId = $request->getParam('shopid');
        $shop = Shop::where('id', $shopId)->where('status', 1)->first();
        if ($shop == null) {
            $res['ret'] = 0;
            $res['msg'] = '????????????';
            return $response->getBody()->write(json_encode($res));
        }

        $price = $shop->price;
        $user = $this->user;

        if (!$user->isLogin) {
            $res['ret'] = -1;
            return $response->getBody()->write(json_encode($res));
        }
        if (bccomp($user->money, $price, 2) == -1) {
            $res['ret'] = 0;
            $res['msg'] = '?????????~ ??????????????????????????????' . $price . '??????</br><a href="/user/code">????????????????????????</a>';
            return $response->getBody()->write(json_encode($res));
        }

        $user->money = bcsub($user->money, $price, 2);
        $traffic = $shop->bandwidth();
        $user->transfer_enable += $traffic * 1024 * 1024 * 1024;
        $user->save();

        $bought = new Bought();
        $bought->userid = $user->id;
        $bought->shopid = $shop->id;
        $bought->datetime = time();
        $bought->coupon = '';
        $bought->renew = 0;
        $bought->price = $price;
        $bought->save();

        $res['ret'] = 1;
        $res['msg'] = '????????????';

        return $response->getBody()->write(json_encode($res));
    }

    public function share_account($request, $response, $args)
    {
        return $this->view()->display('user/share_account.tpl'); 
    }

    public function qrcode($request, $response, $args)
    {
        return $this->view()->display('user/qrcode.tpl'); 
    } 

    public function changeLang($request, $response, $args)
    {
        $lang = $request->getParam('lang');

        $supported_lang = ['zh-cn','en'];
        if (!in_array($lang, $supported_lang)) {
            $res['ret'] = 0;
            $res['msg'] = 'unsupport lang';
        }

        $this->user->lang = $lang;
        $this->user->save();
        
        $res['msg'] = 'yes';
        $res['ret'] = 1;
        return $this->echoJson($response, $res);
    }

    /**
     * ?????????????????????????????????????????????
     *
     * @param Request  $request
     * @param Response $response
     * @param array    $args
     */
    public function getPcClient($request, $response, $args)
    {
        $zipArc = new \ZipArchive();
        $user_token = LinkController::GenerateSSRSubCode($this->user->id, 0);
        $type = trim($request->getQueryParams()['type']);
        // ????????????????????????
        $temp_file_path = '../storage/';
        // ???????????????????????????
        $client_path = '../resources/clients/';
        switch ($type) {
            case 'ss-win':
                $temp_file_path .= $type . '_' . $user_token . '.zip';
                $user_config_file_name = 'gui-config.json';
                $content = LinkController::getSSPcConf($this->user);
                $client_path .= $type . '/';
                break;
            case 'ssd-win':
                $temp_file_path .= $type . '_' . $user_token . '.zip';
                $user_config_file_name = 'gui-config.json';
                $content = LinkController::getSSDPcConf($this->user);
                $client_path .= $type . '/';
                break;
            case 'ssr-win':
                $temp_file_path .= $type . '_' . $user_token . '.zip';
                $user_config_file_name = 'gui-config.json';
                $content = LinkController::getSSRPcConf($this->user);
                $client_path .= $type . '/';
                break;
            case 'v2rayn-win':
                $temp_file_path .= $type . '_' . $user_token . '.zip';
                $user_config_file_name = 'guiNConfig.json';
                $content = LinkController::getV2RayPcNConf($this->user);
                $client_path .= $type . '/';
                break;
            default:
                return 'gg';
        }
        // ????????????????????????
        if (is_file($temp_file_path)) {
            unlink($temp_file_path);
        }
        // ?????????????????????
        $site_url_content = '[InternetShortcut]' . PHP_EOL . 'URL=' . Config::get('baseUrl');
        // ?????? zip ???????????????
        $zipArc->open($temp_file_path, \ZipArchive::CREATE);
        $zipArc->addFromString($user_config_file_name, $content);
        $zipArc->addFromString('????????????_' . Config::get('appName') . '.url', $site_url_content);
        Tools::folderToZip($client_path, $zipArc, strlen($client_path));
        $zipArc->close();

        $newResponse = $response->withHeader('Content-type', ' application/octet-stream')->withHeader('Content-Disposition', ' attachment; filename=' . $type . '.zip');
        $newResponse->write(file_get_contents($temp_file_path));
        unlink($temp_file_path);

        return $newResponse;
    }

    public function getClientfromToken($request, $response, $args)
    {
        $token = $args['token'];
        $Etoken = Token::where('token', '=', $token)->where('create_time', '>', time() - 60 * 10)->first();
        if ($Etoken == null) {
            return '??????????????????????????????????????????????????????.';
        }
        $user = User::find($Etoken->user_id);
        if ($user == null) {
            return null;
        }
        $this->user = $user;
        return self::getPcClient($request, $response, $args);
    }

    /**
     * ??????????????????
     *
     * @param Request  $request
     * @param Response $response
     * @param array    $args
     */
    public function cleanSubCache($request, $response, $args)
    {
        $this->user->cleanSubCache();

        $res['ret'] = 1;
        $res['msg'] = '????????????';

        return $this->echoJson($response, $res);
    }
}
