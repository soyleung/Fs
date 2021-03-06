<?php 

namespace App;

use \Swoole\HTTP\Server;
use \Swoole\HTTP\Request;
use \Swoole\HTTP\Response;
use \Swoole\Coroutine;

class swooleHttpServer extends \Prefab {

	protected
		//! Framework instance
		$fw;

	public function __construct(Server $server) {
		$this->atomic = new \Swoole\Atomic(1); // 进程隔离
		$this->fw = \Base::instance();
		$this->set($server);
		$this->register($server);
	}

	private function set(Server $server) {

		$server->set(array(
			'reactor_num'   => 16,	 // reactor thread num
			'worker_num'	=> 16,	 // worker process num
			'backlog'	   => 128,   // listen backlog
			'task_worker_num'=> 4,
			'max_request'   => 50,
			'dispatch_mode' => 1,
		));
	}
	private function register(Server $server) {

		$server->on('start', [$this, 'onStart']);
		$server->on('receive', [$this, 'onReceive']);
		$server->on('task', [$this, 'onTask']);
		$server->on('finish', [$this, 'onFinish']);
		$server->on('shutdown', [$this, 'onShutdown']);
		$server->on('request', [$this, 'onRequest']);
	}

	private function debug(string $message) {

		$date = date('Y-m-d H:i:s');
		$memory = round(memory_get_usage(true) / 1000 / 1000, 3) . ' MB';
		fwrite(STDOUT, $date . ' | ' . $memory . ' | ' . $message . "\n");
	}

	public function onStart(Server $server) {

		$this->debug(sprintf('Swoole http server is started at http://%s:%s', $server->host, $server->port), PHP_EOL);
	}

	/**
	 * callback function is executed in the worker process
	 */
	public function onReceive($http, $fd, $from_id, $data) {

		// Deliver asynchronous tasks
		$task_id = $http->task($data);
		echo "Dispatch AsyncTask: id=$task_id\n";
	}

	/**
	 * Processing asynchronous tasks (this callback function executes in the task process)
	 */
	public function onTask($http, $task_id, $from_id, $data) {

		echo "New AsyncTask[id=$task_id]".PHP_EOL;
		// Return the result of task execution
		$http->finish("$data -> OK");
	}

	/**
	 * Results of processing asynchronous tasks (this callback function is executed in the worker process)
	 */
	public function onFinish($http, $task_id, $data) {

		echo "AsyncTask[$task_id] Finish: $data".PHP_EOL;
	}
	public function onShutdown(Server $server) {

		$this->debug('Swoole http server Shutting down');
	}

	public function onRequest(Request $swooleRequest, Response $swooleResponse) {

		// if ($swooleRequest->server['path_info'] == '/favicon.ico' || $swooleRequest->server['request_uri'] == '/favicon.ico') {
		// 	$swooleResponse->end();
		// 	return;
		// }

		// \co::sleep(2.2); // Test 
		$this->process($swooleRequest, $swooleResponse);
		$swooleResponse->end($this->atomic->add(1));// 进程隔离
		// $swooleResponse->end();// 没有进程隔离
	}

	public function process(Request $swooleRequest, Response $swooleResponse) {

		\go(function() {
			\co::sleep(1.0);
			echo "co[1] end\n";
		});
		\go(function () {
			\co::sleep(3.0);
			echo "co[2] end\n";
		});
	    // list($controller, $action) = explode('/', trim($swooleRequest->server['request_uri'], '/'));
	    // //根据 $controller, $action 映射到不同的控制器类和方法
	    // (new $controller)->$action($swooleRequest, $swooleResponse);
		\go(function () use ($swooleRequest, $swooleResponse) {
			$processed_fw = $this->convertToFatFreeRequest($swooleRequest, $swooleResponse);
			$this->convertToSwooleResponse($swooleResponse, $processed_fw);
		});

	}

	protected function convertToFatFreeRequest(Request $swooleRequest, Response $swooleResponse) {

		$processed_fw = clone $this->fw;
		// $processed_fw = $this->fw->recursive($this->fw, function($val){
		// 	return $val;
		// });
		// copy server vars
		foreach ($swooleRequest->server as $key=>$val) {
			$tmp=strtoupper($key);
			$_SERVER[$tmp] = $val;
		}

		// copy headers
		$headers = [];
		foreach ($swooleRequest->header as $key=>$val) {
			$tmp=strtoupper(strtr($key,'-','_'));
			// TODO: use ucwords delimiters for php 5.4.32+ & 5.5.16+
			$key=strtr(ucwords(strtolower(strtr($key,'-',' '))),' ','-');
			$headers[$key]=$val;
			if (isset($_SERVER['HTTP_'.$tmp]))
				$headers[$key]=&$_SERVER['HTTP_'.$tmp];
		}

		
		//print_r($swooleRequest->header);
		//print_r($_SERVER);

		$base=rtrim($processed_fw->fixslashes(
			dirname($_SERVER['SCRIPT_NAME'])),'/');
		$scheme=isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on' ||
			isset($headers['X-Forwarded-Proto']) &&
			$headers['X-Forwarded-Proto']=='https'?'https':'http';
		$uri=parse_url((preg_match('/^\w+:\/\//',$_SERVER['REQUEST_URI'])?'':
				$scheme.'://'.$_SERVER['SERVER_NAME']).$_SERVER['REQUEST_URI']);
		$path=preg_replace('/^'.preg_quote($base,'/').'/','',$uri['path']);
		$error=function($url,$permanent) use ($swooleResponse) { 
			$swooleResponse->redirect($url); 
		};

		$val = [
		'HEADERS' => &$headers,
		'AGENT' => $processed_fw->agent(),
		'AJAX' => $processed_fw->ajax(),
		'BODY' => $swooleRequest->rawContent(),
		'CLI' => FALSE,
		'FRAGMENT' => isset($uri['fragment'])?$uri['fragment']:'',
		'HOST' => $_SERVER['SERVER_NAME'],
		'PATH' => $path,
		'QUERY' => isset($uri['query'])?$uri['query']:'',
		'ROOT' => $_SERVER['DOCUMENT_ROOT'],
		'SCHEME' => $scheme,
		'SEED' => $processed_fw->hash($_SERVER['SERVER_NAME'].$base),
		'TIME' => &$_SERVER['REQUEST_TIME_FLOAT'],
		'URI' => &$_SERVER['REQUEST_URI'],
		'VERB' => &$_SERVER['REQUEST_METHOD'],
		'ONREROUTE' => &$error
		];

		foreach ($val as $hive => $value) {
			$$hive = &$processed_fw->ref($hive);
			$$hive = $value;
		}
		foreach (explode('|',\Base::GLOBALS) as $global) {
			$lowercase_global = strtolower($global);
			$globalval = &$processed_fw->ref($global);
			$globalval = $global === 'SERVER' ? array_combine(array_map('strtoupper', array_keys($swooleRequest->{$lowercase_global})), $swooleRequest->{$lowercase_global}) : $swooleRequest->{$lowercase_global};
		}
		// $processed_fw->sync('REQUEST');
		$processed_fw->run();
		return $processed_fw;
	}

	protected function convertToSwooleResponse(Response $swooleResponse, \Base $processed_fw) {
		if(!empty($processed_fw->RESPONSE)) {
			$swooleResponse->header('Content-Length', (string) strlen($processed_fw->RESPONSE));
			$swooleResponse->header('Server', (string) $processed_fw->PACKAGE);
		}

		// deal with cookies
		// if($processed_fw->HEADERS['Set-Cookie']) {

		// }

		// need some work on capturing the status code
		$swooleResponse->status(isset($processed_fw->ERROR['code']) ? $processed_fw->ERROR['code'] : 200);

		if($processed_fw->RESPONSE) {
			$swooleResponse->write($processed_fw->RESPONSE);
		}
		
	}
}
