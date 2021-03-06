<?php
namespace suframe\sunny;
/**
 * ngrok.cc 内网穿透服务 PHP 版
 *
 * 本程序仅适用于ngrok.cc 使用前请先在 https://ngrok.cc 注册账号.
 * 机器只要装有php(无须web服务)即可运行本程序,可用于路由器等OpenWRT操作系统
 * 命令行模式执行 php sunny.php --authtoken=xxxxxx 即可运行
 *
 * 感谢 dosgo 提供的 ngrok-php 原版程序
 *
 */
// 获取传入的参数
class SunnySdk{
    protected $clientid= '';
    // 隧道数组
    protected $Tunnels = array();
    // 根据隧道id请求接口获取隧道信息
    protected $serverArr;
    protected $seraddr;//服务器地址
    protected $port;//端口
    protected $is_verify_peer = false;//是否校验证书
    protected $isDebug = false;//调试开关
    //定义变量
    protected $readfds = array();
    protected $writefds = array();
    protected $e = null;
    protected $t = 1;
    protected $socklist = array();
    protected $ClientId = '';
    protected $recvflag = true;
    protected $starttime;//启动时间
    protected $pingtime = 0;
    protected $mainsocket;

    public function __construct($clientid)
    {
        set_time_limit(0);//设置执行时间
        ignore_user_abort(true);
        error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
        //检测大小端
        define('BIG_ENDIAN', pack('L', 1) === pack('N', 1));

        $this->clientid = $clientid;
        $this->serverArr = $this->sunny_ngrok_auth($clientid);
        $this->seraddr = $this->serverArr[0];
        $this->port = $this->serverArr[1];
        $this->starttime = time();
        register_shutdown_function([$this, 'sunny_shutdown']);
    }

    public function run(){
        $this->sunny_ConsoleOut("欢迎使用内网穿透 sunny-php v1.38\r\nCtrl+C 退出");
        $this->mainsocket = $this->sunny_connectremote($this->seraddr, $this->port);


        if ($this->mainsocket) {
            $this->socklist[] = array('sock' => $this->mainsocket, 'linkstate' => 0, 'type' => 1);
        }
//注册退出执行函数

        while ($this->recvflag) {
            //重排
            array_filter($this->socklist);
            sort($this->socklist);
            //检测控制连接是否连接.
            if ($this->mainsocket == false) {
                $ip = $this->sunny_dnsopen($this->seraddr);//解析dns
                if (!$ip) {
                    $this->sunny_ConsoleOut('连接ngrok服务器失败.');
                    sleep(1);
                    continue;
                }
                $mainsocket = $this->sunny_connectremote($ip, $this->port);
                if (!$mainsocket) {
                    $this->sunny_ConsoleOut('连接ngrok服务器失败.');
                    sleep(10);
                    continue;
                }
                $this->socklist[] = array('sock' => $mainsocket, 'linkstate' => 0, 'type' => 1);
            }
            //如果非cli超过1小时自杀
            if ($this->sunny_is_cli() == false) {
                if ($this->starttime + 3600 < time()) {
                    fclose($this->mainsocket);
                    $this->recvflag = false;
                    break;
                }
            }
            //发送心跳
            if ($this->pingtime + 25 < time() && $this->pingtime != 0) {
                $this->sunny_sendpack($this->mainsocket, $this->sunny_Ping());
                $this->pingtime = time();
            }
            //重新赋值
            $this->readfds = array();
            $this->writefds = array();
            foreach ($this->socklist as $k => $z) {
                if (is_resource($z['sock'])) {
                    $this->readfds[] = $z['sock'];
                    if ($z['linkstate'] == 0) {
                        $this->writefds[] = $z['sock'];
                    }
                } else {
                    //close的时候不是资源。。移除
                    if ($z['type'] == 1) {
                        $this->mainsocket = false;
                    }
                    array_splice($this->socklist, $k, 1);
                }
            }
            //查询
            $res = stream_select($this->readfds, $this->writefds, $this->e, $this->t);
            if ($res === false) {
                $this->sunny_ConsoleOut('sockerr', 'debug');
            }
            //有事件
            if ($res > 0) {
                foreach ($this->socklist as $k => $sockinfo) {
                    $sock = $sockinfo['sock'];
                    //可读
                    if (in_array($sock, $this->readfds)) {
                        $recvbut = fread($sock, 1024);
                        if ($recvbut == false || strlen($recvbut) == 0) {
                            //主连接关闭，关闭所有
                            if ($sockinfo['type'] == 1) {
                                $this->mainsocket = false;
                            }
                            if ($sockinfo['type'] == 3) {
                                fclose($sockinfo['tosock']);
                            }
                            unset($this->socklist[$k]);
                            continue;
                        }
                        if (strlen($recvbut) > 0) {
                            if (!isset($sockinfo['recvbuf'])) {
                                $sockinfo['recvbuf'] = $recvbut;
                            } else {
                                $sockinfo['recvbuf'] = $sockinfo['recvbuf'] . $recvbut;
                            }
                            $this->socklist[$k] = $sockinfo;

                        }
                        //控制连接，或者远程未连接本地连接
                        if ($sockinfo['type'] == 1 || ($sockinfo['type'] == 2 && $sockinfo['linkstate'] == 1)) {
                            $allrecvbut = $sockinfo['recvbuf'];
                            //处理
                            $lenbuf = substr($allrecvbut, 0, 8);
                            $len = $this->sunny_tolen1($lenbuf);
                            if (strlen($allrecvbut) >= (8 + $len)) {
                                $json = substr($allrecvbut, 8, $len);
                                $this->sunny_ConsoleOut($json, 'debug');
                                $js = json_decode($json, true);
                                //远程主连接
                                if ($sockinfo['type'] == 1) {
                                    if ($js['Type'] == 'ReqProxy') {
                                        $newsock = $this->sunny_connectremote($this->seraddr, $this->port);
                                        if ($newsock) {
                                            $this->socklist[] = array('sock' => $newsock, 'linkstate' => 0, 'type' => 2);
                                        }
                                    }
                                    if ($js['Type'] == 'AuthResp') {
                                        $this->ClientId = $js['Payload']['ClientId'];
                                        $this->pingtime = time();
                                        $this->sunny_sendpack($sock, $this->sunny_Ping());
                                        foreach ($this->Tunnels as $tunnelinfo) {
                                            //注册端口
                                            $this->sunny_sendpack($sock,
                                                $this->sunny_ReqTunnel($tunnelinfo['protocol'], $tunnelinfo['hostname'],
                                                    $tunnelinfo['subdomain'], $tunnelinfo['httpauth'], $tunnelinfo['rport']));
                                        }
                                    }

                                    if ($js['Type'] == 'NewTunnel') {
                                        if ($js['Payload']['Error'] != null) {
                                            $this->sunny_ConsoleOut('隧道建立失败:' . $js['Payload']['Error']);
                                            sleep(30);
                                        } else {
                                            $this->sunny_ConsoleOut('隧道建立成功:' . $js['Payload']['Url']);
                                        }
                                    }
                                }
                                //远程代理连接
                                if ($sockinfo['type'] == 2) {
                                    //未连接本地
                                    if ($sockinfo['linkstate'] == 1) {
                                        if ($js['Type'] == 'StartProxy') {
                                            $loacladdr = $this->sunny_getloacladdr($this->Tunnels, $js['Payload']['Url']);
                                            $newsock = $this->sunny_connectlocal($loacladdr['lhost'], $loacladdr['lport']);
                                            if ($newsock) {
                                                $this->socklist[] = array(
                                                    'sock' => $newsock,
                                                    'linkstate' => 0,
                                                    'type' => 3,
                                                    'tosock' => $sock
                                                );
                                                //把本地连接覆盖上去
                                                $sockinfo['tosock'] = $newsock;
                                                $sockinfo['linkstate'] = 2;
                                            } else {
                                                $body = '<!DOCTYPE html><html lang=""><head><meta charset="utf-8"><title>Web服务错误</title><meta name="viewport" content="initial-scale=1,maximum-scale=1,user-scalable=no"><meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"><style>html,body{height:100%%}body{margin:0;padding:0;width:100%%;display:table;font-weight:100;font-family:"Microsoft YaHei",Arial,Helvetica,sans-serif}.container{text-align:center;display:table-cell;vertical-align:middle}.content{border:1px solid #ebccd1;text-align:center;display:inline-block;background-color:#f2dede;color:#a94442;padding:30px}.title{font-size:18px}.copyright{margin-top:30px;text-align:right;color:#000}</style></head><body><div class="container"><div class="content"><div class="title">隧道 %s 无效<br>无法连接到<strong>%s</strong>. 此端口尚未提供Web服务</div></div></div></body></html>';
                                                $html = sprintf($body, $js['Payload']['Url'],
                                                    $loacladdr['lhost'] . ':' . $loacladdr['lport']);
                                                $header = "HTTP/1.0 502 Bad Gateway" . "\r\n";
                                                $header .= "Content-Type: text/html" . "\r\n";
                                                $header .= "Content-Length: %d" . "\r\n";
                                                $header .= "\r\n" . "%s";
                                                $buf = sprintf($header, strlen($html), $html);
                                                $this->sunny_sendbuf($sock, $buf);
                                            }
                                        }
                                    }
                                }
                                //edit buffer
                                if (strlen($allrecvbut) == (8 + $len)) {
                                    $sockinfo['recvbuf'] = '';
                                } else {
                                    $sockinfo['recvbuf'] = substr($allrecvbut, 8 + $len);
                                }
                                $this->socklist[$k] = $sockinfo;
                            }
                        }
                        //远程连接已连接本地跟本地连接，纯转发
                        if ($sockinfo['type'] == 3 || ($sockinfo['type'] == 2 && $sockinfo['linkstate'] == 2)) {
                            $this->sunny_sendbuf($sockinfo['tosock'], $sockinfo['recvbuf']);
                            $sockinfo['recvbuf'] = '';
                            $this->socklist[$k] = $sockinfo;
                        }
                    }
                    //可写
                    if (in_array($sock, $this->writefds)) {
                        if ($sockinfo['linkstate'] == 0) {
                            if ($sockinfo['type'] == 1) {
                                $this->sunny_sendpack($sock, $this->sunny_NgrokAuth(), false);
                                $sockinfo['linkstate'] = 1;
                                $this->socklist[$k] = $sockinfo;
                            }
                            if ($sockinfo['type'] == 2) {
                                $this->sunny_sendpack($sock, $this->sunny_RegProxy($this->ClientId), false);
                                $sockinfo['linkstate'] = 1;
                                $this->socklist[$k] = $sockinfo;
                            }
                            if ($sockinfo['type'] == 3) {
                                $sockinfo['linkstate'] = 1;
                                $this->socklist[$k] = $sockinfo;
                            }
                        }
                    }
                }
            }
        }
    }


    /* 域名解析 */
    function sunny_dnsopen($host)
    {
        $ip = gethostbyname($host);//解析dns
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return $ip;
    }

    /* 连接到远程 */
    function sunny_connectremote($seraddr, $port)
    {
        // 连接获取socket资源
        $socket = stream_socket_client('tcp://' . $seraddr . ':' . $port, $errno, $errstr, 30);
        if (!$socket) {
            return false;
        }
        //设置加密连接，默认是ssl，如果需要tls连接，可以查看php手册stream_socket_enable_crypto函数的解释
        if ($this->is_verify_peer == false) {
            stream_context_set_option($socket, 'ssl', 'verify_host', false);
            stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($socket, 'ssl', 'verify_peer', false);
            stream_context_set_option($socket, 'ssl', 'allow_self_signed', false);
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
        stream_set_blocking($socket, 0);//设置为非阻塞模式
        return $socket;
    }

    /* 连接到本地 */
    function sunny_connectlocal($localaddr, $localport)
    {
        $socket = stream_socket_client('tcp://' . $localaddr . ':' . $localport, $errno, $errstr, 30);
        if (!$socket) {
            return false;
        }
        stream_set_blocking($socket, 0);//设置为非阻塞模式
        return $socket;
    }

    function sunny_getloacladdr($Tunnels, $url)
    {
        $protocol = substr($url, 0, strpos($url, ':'));
        $hostname = substr($url, strpos($url, '//') + 2);
        $subdomain = trim(substr($hostname, 0, strpos($hostname, '.')));
        $rport = substr($url, strrpos($url, ':') + 1);
        //   echo 'protocol:'.$protocol."\r\n";
        //   echo '$subdomain:'.$subdomain."\r\n";
        //      echo '$hostname:'.$hostname."\r\n";
        //    echo '$rport:'.$rport."\r\n";
        foreach ($Tunnels as $k => $z) {
            //
            if ($protocol == $z['protocol']) {
                if ($hostname == $z['hostname']) {
                    return $z;
                }
                if ($subdomain == $z['subdomain']) {
                    return $z;
                }
            }
            if ($protocol == 'tcp') {
                if ($rport == $z['rport']) {
                    return $z;
                }
            }
        }
        //  array('protocol'=>$protocol,'hostname'=>'','subdomain'=>'','rport'=>0,'lhost'=>'','lport'=>80),
        return true;
    }

    function sunny_NgrokAuth()
    {
        $Payload = array(
            'ClientId' => '',
            'OS' => 'darwin',
            'Arch' => 'amd64',
            'Version' => '2',
            'MmVersion' => '2.1',
            'User' => 'user',
            'Password' => '',
        );
        $json = array(
            'Type' => 'Auth',
            'Payload' => $Payload,
        );
        return json_encode($json);
    }

    function sunny_ReqTunnel($protocol, $HostName, $Subdomain, $HttpAuth, $RemotePort)
    {
        $Payload = array(
            'ReqId' => $this->sunny_getRandChar(8),
            'Protocol' => $protocol,
            'Hostname' => $HostName,
            'Subdomain' => $Subdomain,
            'HttpAuth' => $HttpAuth,
            'RemotePort' => $RemotePort,
        );
        $json = array(
            'Type' => 'ReqTunnel',
            'Payload' => $Payload,
        );
        return json_encode($json);
    }

    function sunny_RegProxy($ClientId)
    {
        $Payload = array('ClientId' => $ClientId);
        $json = array(
            'Type' => 'RegProxy',
            'Payload' => $Payload,
        );
        return json_encode($json);
    }

    function sunny_Ping()
    {
        $Payload = (object)array();
        $json = array(
            'Type' => 'Ping',
            'Payload' => $Payload,
        );
        return json_encode($json);
    }

    /* 网络字节序 （只支持整型范围） */
    function sunny_lentobyte($len)
    {
        $xx = pack("N", $len);
        $xx1 = pack("C4", 0, 0, 0, 0);
        return $xx1 . $xx;
    }

    /* 机器字节序 （小端 只支持整型范围） */
    function sunny_lentobyte1($len)
    {
        $xx = pack("L", $len);
        $xx1 = pack("C4", 0, 0, 0, 0);
        return $xx . $xx1;
    }

    function sunny_sendpack($sock, $msg, $isblock = true)
    {
        if ($isblock) {
            stream_set_blocking($sock, 1);//设置为非阻塞模式
        }
        fwrite($sock, $this->sunny_lentobyte1(strlen($msg)) . $msg);
        if ($isblock) {
            stream_set_blocking($sock, 0);//设置为非阻塞模式
        }
    }

    function sunny_sendbuf($sock, $buf, $isblock = true)
    {
        if ($isblock) {
            stream_set_blocking($sock, 1);//设置为非阻塞模式
        }
        fwrite($sock, $buf);
        if ($isblock) {
            stream_set_blocking($sock, 0);//设置为非阻塞模式
        }
    }

    /* 网络字节序 （只支持整型范围） */
    function sunny_tolen($v)
    {
        $array = unpack("N", $v);
        return $array[1];
    }

    /* 机器字节序 （小端） 只支持整型范围 */
    function sunny_tolen1($v)
    {
        $array = unpack("L", $v);
        return $array[1];
    }

//随机生成字符串
    function sunny_getRandChar($length)
    {
        $str = null;
        $strPol = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz";
        $max = strlen($strPol) - 1;
        for ($i = 0; $i < $length; $i++) {
            $str .= $strPol[rand(0, $max)];
        }
        return $str;
    }

//输出日记到命令行
    function sunny_ConsoleOut($log, $level = 'info')
    {
        $isDebug = $this->isDebug;
        if ($level == 'debug' and $isDebug == false) {
            return;
        }
        //cli
        if ($this->sunny_is_cli()) {
            if (DIRECTORY_SEPARATOR == "\\") {
                $log = iconv('UTF-8', 'GB2312', $log);
            }
            echo $log . "\r\n";
        } //web
        else {
            echo $log . "<br/>";
            ob_flush();
            flush();
            // file_put_contents("ngrok.log", date("Y-m-d H:i:s:::") . $log . "\r\n", FILE_APPEND);
        }
    }

//判断是否命令行运行
    function sunny_is_cli()
    {
        return (php_sapi_name() === 'cli') ? true : false;
    }

//ngrok.cc 获取服务器设置
    function sunny_ngrok_auth($clientid)
    {
        $host = 'www.ngrok.cc';
        $port = 443;
        $fp = stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 10);
        if (!$fp) {
            $this->sunny_ConsoleOut('连接认证服务器: https://www.ngrok.cc 错误.');
            sleep(10);
            exit();
        }
        // 如果不校验证书把证书校验设置成false
        if ($this->is_verify_peer == false) {
            stream_context_set_option($fp, 'ssl', 'verify_host', false);
            stream_context_set_option($fp, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($fp, 'ssl', 'verify_peer', false);
            stream_context_set_option($fp, 'ssl', 'allow_self_signed', false);
        }
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        $header = "GET " . "/api/clientid/clientid/%s" . " HTTP/1.1" . "\r\n";
        $header .= "Host: %s" . "\r\n";
        $header .= "\r\n";
        $buf = sprintf($header, $clientid, $host);
        fputs($fp, $buf);
        $body = null;
        while (!feof($fp)) {
            $line = fgets($fp, 1024);//去除请求包的头只显示页面的返回数据
            if ($line == "\n" || $line == "\r\n") {
                $chunk_size = (integer)hexdec(fgets($fp, 1024));
                if ($chunk_size > 0) {
                    $body = fread($fp, $chunk_size);
                    break;
                }
            }
        }
        fclose($fp);
        $authData = json_decode($body, true);
        if ($authData['status'] != 200) {
            $this->sunny_ConsoleOut('认证错误:' . $authData['msg'] . ' ErrorCode:' . $authData['status']);
            sleep(10);
            exit();
        }
        $this->sunny_ConsoleOut('认证成功,正在连接服务器...');
        //设置映射隧道,支持多渠道[客户端id]
        $this->sunny_ngrok_adds($authData['data']);
        $proto = explode(':', $authData['server']);
        return $proto;
    }

//ngrok.cc 添加到渠道队列
    function sunny_ngrok_adds($Tunnel)
    {
        $protocol = 'http';
        foreach ($Tunnel as $tunnelinfo) {
            if (isset($tunnelinfo['proto']['http'])) {
                $protocol = 'http';
            }
            if (isset($tunnelinfo['proto']['https'])) {
                $protocol = 'https';
            }
            if (isset($tunnelinfo['proto']['tcp'])) {
                $protocol = 'tcp';
            }
            $proto = explode(':', $tunnelinfo['proto'][$protocol]);//127.0.0.1:80 拆分成数组
            if ($proto[0] == '') {
                $proto[0] = '127.0.0.1';
            }
            if ($proto[1] == '' || $proto[1] == 0) {
                $proto[1] = 80;
            }
            $this->Tunnels[] = array(
                'protocol' => $protocol,
                'hostname' => $tunnelinfo['hostname'],
                'subdomain' => $tunnelinfo['subdomain'],
                'httpauth' => $tunnelinfo['httpauth'],
                'rport' => $tunnelinfo['remoteport'],
                'lhost' => $proto[0],
                'lport' => $proto[1],
            );
        }
    }

    //注册退出执行函数
    function sunny_shutdown()
    {
        $mainsocket = $this->mainsocket;
        $this->sunny_sendpack($mainsocket, 'close');
        fclose($mainsocket);
    }

}

?>
