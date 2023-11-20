<?
	namespace ThriveData\ThrivePHP;
	
	class CURL
	{
		public $info;
		public $result;
		public $json;
		
		const GET = 'GET';
		const POST = 'POST';
		const PUT = 'PUT';
		const DELETE = 'DELETE';
		
		public function __construct(
			public string $url,
			public ?string $method = 'get',
			public ?array $headers = [],
			public ?string $data = null,
			public ?string $file = null,
			public ?bool $returnheader = false,
			public ?bool $returntransfer = true,
			public ?bool $follow = true,
		) {
			printf("CURL::__construct()\n");
			
			$c = curl_init();
			
			$options = [
				\CURLOPT_URL => $url,
				\CURLOPT_RETURNTRANSFER => $returntransfer,
				\CURLOPT_HEADER => $returnheader,
				\CURLINFO_HEADER_OUT => true,
				\CURLOPT_CONNECTTIMEOUT => 5,
				\CURLOPT_TIMEOUT => 180,
				\CURLOPT_MAXREDIRS => 10,
			];
			
			curl_setopt_array($c, $options);
			
			switch ($method):
				case self::POST:
					curl_setopt($c, CURLOPT_POST, true);
					curl_setopt($c, CURLOPT_POSTFIELDS, $data);   // if $data is an array Content-Type will be multipart/form-data, if string then application/x-www-form-urlencoded
					break;
				case self::PUT:
					curl_setopt($c, CURLOPT_CUSTOMREQUEST, 'PUT');
					curl_setopt($c, CURLOPT_POSTFIELDS, $data);   // if $data is an array Content-Type will be multipart/form-data, if string then application/x-www-form-urlencoded
					break;
			endswitch;
			
			if ($headers):
				curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
			endif;
			
			if ($file):
				$fp = fopen($file, 'w+');
				curl_setopt($c, CURLOPT_RETURNTRANSFER, false);   // return result instead of output to stdout
				curl_setopt($c, CURLOPT_FILE, $fp);   // output goes to file
			endif;
			
			$result = curl_exec($c);
			
			if ($result === false):
				$errno = curl_errno($c);
				$errmsg = curl_error($c);
				printf("Thrive:CURL::__construct() | fail | errno=%s | errmsg=%s\n", $errno, $errmsg);
				throw new ExecuteException('unable to execute: '.$errmsg, $errno);
			endif;
			
			$info = curl_getinfo($c);
			$code = $info['http_code'];
			
			if ($code < 200 || $code >= 300):
				switch($code):
					case 301: throw new MovedPermanently('resource moved permanently'); break;
					case 302: throw new MovedTemporarily('resource moved temporarily'); break;
					case 303: throw new SeeOtherRedirect('request completed and response found at other URI'); break;
					case 307: throw new TemporaryRedirect('repeat request at temporary URI');
					case 308: throw new PermanentRedirect('request should change permanently at new URI'); break;
					case 400: throw new BadRequest('the client request is invalid', $code, context: ['result' => $result]); break;
					case 401: throw new Unauthorized('not authorized to access server resources', $code); break;
					case 403: throw new Forbidden('permission denied to resource', $code); break;
					case 404: throw new NotFound('resource not found', $code); break;
					case 500: throw new InternalServerError('the server has an internal error', $code); break;
				endswitch;
				
				if ($code < 200):
					throw new InformationException('unexpected information');
				elseif($code >= 300 && $code < 400):
					throw new RedirectException('unexpected redirect');
				elseif ($code >= 400 && $code < 500):
					throw new RequestException('problem with request');
				elseif ($code >= 500):
					throw new ResponseException('problem with server');
				endif;
				
				throw new Exception('unknown response');
			endif;
			
			$this->info = $info;
			$this->result = $result;
			
			if (str_starts_with($info['content_type'], 'application/json')):
				$this->json = json_decode($result);
			endif;
		}
	}
	
	class CURLException extends ContextException {}
	
	// curl error
	class ExecuteException extends CURLException {}
	
	// 1xx info
	class InformationException extends CURLException {}
	
	// 3xx redirection
	class RedirectException extends CURLException {}
	class MovedPermanently extends RedirectException {}   // 301
	class MovedTemporarily extends RedirectException {}   // 302
	class SeeOtherRedirect extends RedirectException {}   // 303
	class TemporaryRedirect extends RedirectException {}   // 307
	class PermanentRedirect extends RedirectException {}   // 308
	
	// 4xx client error
	class RequestException extends CURLException {}
	class BadRequest extends RequestException {}   // 400
	class Unauthorized extends RequestException {}   // 401
	class NotFound extends RequestException {}   // 404
	
	// 5xx server error
	class ResponseException extends CURLException {}
	class InternalServerError extends ResponseException {}   // 500
