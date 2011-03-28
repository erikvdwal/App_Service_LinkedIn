<?php
/**
 * LinkedIn Service class for use with the Zend Framework.
 * 
 * This class can be used to access the LinkedIn API. Full API documentation
 * is available via http://developer.linkedin.com/.
 *
 * @author Erik van der Wal <erikvdwal@gmail.com>
 * @copyright Erik van der Wal, 25 maart, 2011
 * @package App_Service
 **/

/**
 * @see Zend_Rest_Client
 */
require_once 'Zend/Rest/Client.php';

/**
 * @see Zend_Rest_Client_Result
 */
require_once 'Zend/Rest/Client/Result.php';

/**
 * @package App_Service
 * @subpackage LinkedIn
 */
class App_Service_LinkedIn extends Zend_Rest_Client
{
	/**
	 * Base URL for API
	 */
	const API_BASE_URL = 'https://api.linkedin.com';

	/**
	 * Http Client
	 *
	 * @var Zend_Http_Client client
	 */
	protected $_localHttpClient = null;

	/**
	 * Options
	 *
	 * @var array options
	 */
	protected $_options;

	/**
	 * Constructor
	 */
	public function  __construct($options = null, $consumer = null)
	{
		if ($options instanceof Zend_Config) {
			$options = $options->toArray();
		}
		
		if (!is_array($options)) {
			$options = array();
		}
		
		$this->_options = $options;
		
		if ($consumer instanceof Zend_Oauth_Consumer) {
			$this->_oauthConsumer = $consumer;
		} else {
			$this->_oauthConsumer = new Zend_Oauth_Consumer($options);
		}
				
		if (isset($options['accessToken'])) {
			// Get http client from accesstoken (if provided)
			$this->setLocalHttpClient($options['accessToken']->getHttpClient($options));
		} else {
			$this->setLocalHttpClient(clone self::getHttpClient());
		}
	}

	/**
	 * Set/overload the local http client
	 *
	 * @param Zend_Http_Client $client client
	 * @return App_Service_LinkedIn
	 */
	public function setLocalHttpClient(Zend_Http_Client $client)
	{
		$this->_localHttpClient = $client;
		$this->_localHttpClient->setUri(self::API_BASE_URL);
		return $this;
	}
	
	/**
	 * Returns wether or not the http client is authenticated.
	 *
	 * @return bool true when authenticated
	 */
	public function isAuthorized()
	{
		if ($this->_localHttpClient instanceof Zend_Oauth_Client) {
			return true;
		}
		return false;
	}
	
	/**
	 * Get the user profile. When $includeCustomFields is set to true,
	 * all custom fields are included.
	 *
	 * @param string|array $user user name, array with id or url
	 * @param bool $includeCustomFields include custom fields or not
	 * @return Zend_Rest_Client_Result response
	 */
	public function userProfile($user = null, $includeCustomFields = false)
	{
		$path = '/v1/people/%s';
		$path = sprintf($path, ($user ? $this->_parseUserParameter($user) : '~'));

		if ($includeCustomFields) {
			$fields = array('first-name', 'last-name', 'interests', 'positions', 'phone-numbers', 'num-recommenders', 'recommendations-received', 'honors', 'associations', 'specialties', 'connections', 'twitter-accounts', 'im-accounts', 'headline', 'summary', 'current-status', 'picture-url', 'date-of-birth', 'public-profile-url');
			$path .= ':(' . implode(',', $fields) . ')';
		}
		
		$response = $this->_get($path);
		return new Zend_Rest_Client_Result($response->getBody());
	}

	/**
	 * Get user connections. If no user is provided, connections for
	 * the authenticating user are retreived.
	 *
	 * @param string|array $user user name, array with id or url
	 * @param array $params additional paramters
	 * @return Zend_Rest_Client_Result response
	 */
	public function userConnections($user = null, $params = array())
	{
		$path = '/v1/people/%s/connections';
		$path = sprintf($path, ($user ? (string) $this->_parseUserParameter($user) : '~'));
		$response = $this->_get($path, $params);
		return new Zend_Rest_Client_Result($response->getBody());	
	}

	/**
	 * Search by a person, schools or companies.
	 *
	 * @param array $params search parameters paramters
	 * @return Zend_Rest_Client_Result response
	 */
	public function search(array $params)
	{
		$validKeys = array('keywords', 'first-name', 'last-name', 'company-name', 'current-company', 'title', 'school', 'current-school', 'country-code', 'postal-code', 'distance', 'start', 'count', 'facet', 'facets', 'sort');
		$params = array_flip(array_intersect(array_flip($params), $validKeys));
		$params = array_map('urlencode', $params);

		$response = $this->_get('/v1/people-search', $params);
		return new Zend_Rest_Client_Result($response->getBody());
	}

	/**
	 * Send a message to a user or multiple users.
	 *
	 * @param string $subject message subject
	 * @param string $body message body (may include some html)
	 * @param array $recipients recipients
	 */
	public function message($subject, $body, $recipients)
	{
		if (!is_array($recipients)) {
			throw new Exception('Recipients must be suplied as an array');
		}

		// Start document
		$xml = new DOMDocument('1.0', 'utf-8');

		// Create element for recipients and add each recipient as a node
		$elemRecipients = $xml->createElement('recipients');
		foreach ($recipients as $recipient) {
			// Create person node
			$person = $xml->createElement('person');
			$person->setAttribute('path', '/people/' . (string) $recipient);

			// Create recipient node
			$elemRecipient = $xml->createElement('recipient');
			$elemRecipient->appendChild($person);

			// Add recipient to recipients node
			$elemRecipients->appendChild($elemRecipient);
		}

		// Set up filter
		$filter = new Zend_Filter();
		$filter->addFilter(new Zend_Filter_StripTags());
		$filter->addFilter(new Zend_Filter_StringTrim());

		// Create mailbox node and add recipients, body and subject
		$elemMailbox = $xml->createElement('mailbox-item');
		$elemMailbox->appendChild($elemRecipients);
		$elemMailbox->appendChild($xml->createElement('body', $filter->filter($body)));
		$elemMailbox->appendChild($xml->createElement('subject', $filter->filter($subject)));

		// Append parent node to document
		$xml->appendChild($elemMailbox);

		$response = $this->_post('/v1/people/~/mailbox', $xml->saveXML());
		return ($response->getStatus() == 201 ? true : false);
	}

	/**
	 * Get the latest network activities including status and profile updates.
	 *
	 * @param string|array $user user name, array with id or url
	 * @param array $params additional parameters
	 * @return Zend_Rest_Client_Result response
	 */
	public function networkActivities($user = null, $params = array())
	{
		$path = '/v1/people/%s/network/updates';
		$path = sprintf($path, ($user ? $this->_parseUserParameter($user) : '~'));

		$response = $this->_get($path, $params);
		return new Zend_Rest_Client_Result($response->getBody());
	}

	/**
	 * Update a users' network status. This is pretty simular to the
	 * user status, except it appears in a different place (visually)
	 * and by is (by default) only visible to connections.
	 *
	 * @param string $status status
	 * @return bool
	 */
	public function postNetworkUpdate($status)
	{
		$path = '/v1/people/~/person-activities';

		// Create document and create document
		$xml = new DOMDocument('1.0', 'utf-8');
		$elemActivity = $xml->createElement('activity');
		$elemActivity->setAttribute('locale', 'en_US');

		// Append the two required elements
		$elemActivity->appendChild($xml->createElement('content-type', 'linkedin-html'));
		$elemActivity->appendChild($xml->createElement('body', $status));

		// Append activity element to document
		$xml->appendChild($elemActivity);

		$response = $this->_post($path, $xml->saveXML());
		return ($response->getStatus() == 201 ? true : false);
	}

	/**
	 * Update a users' current status. Optionally, this status can
	 * also be posted to twitter of the user has an account
	 * configured.
	 *
	 * @param string $status status
	 * @param bool $postToTwitter post status to twitter
	 * @return bool
	 */
	public function postStatusUpdate($status, $postToTwitter = false)
	{
		$path = '/v1/people/~/current-status' . ($postToTwitter ? '?twitter-post=true' : '');

		// Create document and create document and append the element
		$xml = new DOMDocument('1.0', 'utf-8');
		$xml->appendChild($xml->createElement('current-status', $status));
		
		$response = $this->_put($path, $xml->saveXML());		
		return ($response->getStatus() == 201 ? true : false);
	}

	/**
	 * Make a get request
	 *
	 * @param string $path path to request
	 * @param array $query GET parameters to include
	 * @return Zend_Http_Client_Reponse response
	 */
    protected function _get($path, $query = array())
    {
		$this->_localHttpClient->setUri(self::API_BASE_URL . $path);
		$this->_localHttpClient->setParameterGet($query);		
		return $this->_localHttpClient->request(Zend_Http_Client::GET);
	}

	/**
	 * Make a post request
	 *
	 * @param string $path path to request
	 * @param string $xml data to post
	 * @return Zend_Http_Client_Reponse response
	 */
	protected function _post($path, $data)
	{
		$this->_localHttpClient->setUri(self::API_BASE_URL . $path);
		$this->_localHttpClient->setHeaders('Content-type', 'text/xml; charset=utf-8');

		if (is_string($data)) {
			$this->_localHttpClient->setRawData($data, 'text/xml');
		} elseif (is_array($data) || is_object($data)) {
			$this->_localHttpClient->setParameterPost($data);
		}

		return $this->_localHttpClient->request(Zend_Http_Client::POST);
	}

	/**
	 * Make a put request
	 *
	 * @param string $path path to request
	 * @param string $xml data to post
	 * @return Zend_Http_Client_Reponse response
	 */
	protected function _put($path, $data)
	{
		$this->_localHttpClient->setUri(self::API_BASE_URL . $path);
		$this->_localHttpClient->setHeaders('Content-type', 'text/xml; charset=utf-8');

		if (is_string($data)) {
			$this->_localHttpClient->setRawData($data, 'text/xml');
		} elseif (is_array($data) || is_object($data)) {
			$this->_localHttpClient->setParameterPost($data);
		}

		return $this->_localHttpClient->request(Zend_Http_Client::PUT);
	}

	/**
	 * Parse the user string provided. This can be given as
	 * a string or array with the 'url' and 'id' keys. This function
	 * will turn the input into a string.
	 *
	 * @param string|array $user
	 * @return string
	 */
	private function _parseUserParameter($user = null)
	{
		if (is_array($user)) {
			if (isset($user['url'])) {
				$user = (string) 'url=' . urlencode($user['url']);
			} elseif (isset($user['id'])) {
				$user = (string) 'id=' . (int) $user['id'];
			}
		}
		
		return (string) ($user ? $user : '~');
	}
	
	/**
	 * Method overlodading
	 *
	 * @param string $method method to call
	 * @param string $args params
	 * @return mixed
	 */
	public function __call($method, $params)
	{
		if (method_exists($this->_oauthConsumer, $method)) {
			$return = call_user_func_array(array($this->_oauthConsumer, $method), $params);
			if ($return instanceof Zend_Oauth_Token_Access) {
				$this->setLocalHttpClient($return->getHttpClient($this->_options));
			}
			return $return;
		}
		
		if (!method_exists($this, $method)) {
			throw new Exception('Invalid method ' . $method . ' called.');
		}

		return call_user_method_array(array($method, $this), $params);
	}
}