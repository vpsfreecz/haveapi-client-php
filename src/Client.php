<?php

namespace HaveAPI;

/**
 * A client for a HaveAPI based API.
 */
class Client extends Client\Resource {
	private $uri;
	private $version;
	private $identity;
	private $authProvider;
	private $queryParams;
	private $descCallback = null;
	private static $authProviders = array();
	
	/**
	 * Register authentication provider with $name and implementation in $class.
	 * @param string $name name of authentication method (has to match with names in API)
	 * @param string $class name of a class
	 * @param boolean $force replace the provider if it already exists
	 */
	public static function registerAuthProvider($name, $class, $force = true) {
		if(!$force && in_array($name, self::$authProviders))
			return;
		
		self::$authProviders[$name] = $class;
	}
	
	/**
	 * @param string $uri URL to the API root, do not specify version here
	 * @param mixed $version API version to use, defaults to default version
	 * @param string $identity string to be sent in User-Agent in every request
	 */
	public function __construct($uri = 'http://localhost:4567', $version = null, $identity = 'haveapi-client-php') {
		$this->client = $this;
		$this->uri = chop($uri, '/');
		$this->version = $version;
		$this->identity = $identity;
		
		self::registerAuthProvider('none', 'HaveAPI\Client\AuthenticationProviders\NoAuth');
		self::registerAuthProvider('basic', 'HaveAPI\Client\AuthenticationProviders\Basic');
		self::registerAuthProvider('token', 'HaveAPI\Client\AuthenticationProviders\Token');
		
		$this->authProvider = new Client\AuthenticationProviders\NoAuth($this, array(), array());
	}
	
	/**
	 * Fetch the API description if it isn't fetched yet.
	 * @param boolean $force fetch even it is already fetched
	 */
	public function setup($force = false) {
		if(!$force && $this->description)
			return;
		
		$this->changeDescription($this->fetchDescription());
	}
	
	/**
	 * Return the API description.
	 * @return \stdClass may be NULL if the client is not set up yet
	 */
	public function getDescription() {
		return $this->description;
	}
	
	/**
	 * Set the description to $desc. The client will use this description
	 * and will not ask the API about it.
	 * @param \stdClass $desc
	 */
	public function setDescription($desc) {
		$this->description = $desc;
	}
	
	/**
	 * Register a callback function that will be called when the description
	 * is changed. The function is passed instance of Client as an argument.
	 * @param callable $fn
	 */
	public function registerDescriptionChangeFunc($fn) {
		$this->descCallback = $fn;
	}
	
	/**
	 * Authenticate with $method and options $opts.
	 * @param string $method authentication provider name
	 * @param array $opts options passed to the provider
	 * @param boolean $forceSetup force reloading the description of the API
	 */
	public function authenticate($method, $opts, $forceSetup = true) {
		if(!array_key_exists($method, self::$authProviders))
			throw new Client\Exception\AuthenticationFailed("Auth method '$method' is not registered");
		
		$this->setup();
		
		$this->authProvider = new self::$authProviders[$method]($this, $this->description->authentication->{$method}, $opts);
		
		$this->setup($forceSetup);
	}
	
	/**
	 * Logout the authenticated user and destroy the authentication provider.
	 */
	public function logout() {
		$this->authProvider->logout();
		$this->authProvider = new NoAuth($this, array(), array());
		$this->changeDescription(NULL);
	}
	
	/**
	 * Return the authentication provider.
	 * @return AuthProvider
	 */
	public function getAuthenticationProvider() {
		return $this->authProvider;
	}
	
	/**
	 * Invoke action $action with $params and interpret the response.
	 * @param Action $action
	 * @param array $params
	 * @return mixed response
	 */
	public function call($action, $params = array()) {
		$response = new Client\Response($action, $this->directCall($action, $params));
		
		if(!$response->isOk()) {
			throw new Client\Exception\ActionFailed($response, "Action '".$action->name()."' failed: ".$response->message());
		}
		
		switch($action->layout('output')) {
			case 'object':
				return new Client\ResourceInstance($this->client, $action, $response);
			
			case 'object_list':
				return new Client\ResourceInstanceList($this->client, $action, $response);
			
			default:
				return $response;
		}
	}
	
	/**
	 * Invoke action $action with $params and do notinterpret the response.
	 * @param Action $action
	 * @param array $params
	 * @return mixed response
	 */
	public function directCall($action, $params = array()) {
		$fn = strtolower($action->httpMethod());
		
// 		echo "execute {$action->httpMethod()} {$action->url()}\n<br>\n";
		
		$request = $this->getRequest($fn, $this->uri . $action->url());
		
		if(!$this->sendAsQueryParams($fn))
			$request->body(empty($params) ? '{}' : json_encode(array($action->getNamespace('input') => $params)));
		
		$this->authProvider->authenticate($request);
		
		return $this->sendRequest($request, $action, $params);
	}
	
	/**
	 * Create \Httpful\Request instance.
	 * @param string $method HTTP method
	 * @param string $url
	 */
	protected function getRequest($method, $url) {
		$this->queryParams = array();
		
		$request = \Httpful\Request::$method($url);
		$request->sendsJson();
		$request->expectsJson();
		$request->addHeader('User-Agent', $this->identity);
		
		return $request;
	}
	
	/**
	 * Send \Httpful\Request.
	 * @param \Httpful\Request $request
	 * @param Action $action
	 * @param array $params
	 */
	protected function sendRequest($request, $action = null, $params = array()) {
		$this->queryParams += $this->authProvider->queryParameters();
		
		if($action && $this->sendAsQueryParams($action->httpMethod())) {
			foreach($params as $k => $v) {
				$this->queryParams[ $action->getNamespace('input')."[$k]" ] = $v;
			}
		}
		
		if(count($this->queryParams) > 0) {
			$url = $request->uri;
			$first = true;
			
			foreach($this->queryParams as $k => $v) {
				if($first) {
					$url .= '?';
					$first = false;
				} else $url .= '&';
				
				$url .= $k.'='.urlencode($v);
			}
			
			$request->uri = $url;
		}
		
		return $request->send();
	}
	
	/**
	 * @param string $method
	 * @return boolean true for HTTP methods GET and OPTIONS.
	 */
	protected function sendAsQueryParams($method) {
		return in_array(strtolower($method), array('get', 'options'));
	}
	
	/**
	 * Fetch the description.
	 * @return \stdClass
	 */
	protected function fetchDescription() {
		$url = $this->uri;
		
		if($this->version)
			$url .= "/v".$this->version."/";
		
		$request = $this->getRequest('options', $url);
		
		if(!$this->version)
			$this->queryParams['describe'] = 'default';
		
		$this->authProvider->authenticate($request);
		
		return $this->sendRequest($request)->body->response;
	}
	
	protected function findObject($name, $description = null) {
		$obj = parent::findObject($name, $description);
		
		if($obj instanceof Client\Resource) {
			$obj->setApiClient($this);
		}
		
		return $obj;
	}
	
	private function changeDescription($d) {
		$this->description = $d;
		
		if($this->descCallback)
			call_user_func($this->descCallback, $this);
	}
}