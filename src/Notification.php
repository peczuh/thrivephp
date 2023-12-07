<?
	namespace ThriveData\ThrivePHP;
	
	class Notification
	{
		static function success($message, $detail=null)
		{
			Session::start();
			$_SESSION['notifications']['successes'][] = ['message' => $message, 'detail' => $detail];
		}
		
		static function warning($message, $detail=null)
		{
			Session::start();
			$_SESSION['notifications']['warnings'][] = ['message' => $message, 'detail' => $detail];
		}
		
		static function failure($message, $detail=null)
		{
			Session::start();
			$_SESSION['notifications']['failures'][] = ['message' => $message, 'detail' => $detail];
		}
		
		static function html($clear=true, $print=true)
		{
			if (count($_SESSION['notifications'] ?? []) > 0):
				$html = '';
				foreach ($_SESSION['notifications'] as $category => $notifications):
					$html .='<div class="notifications '.$category.'"><ul>';
					foreach($notifications as $n):
						$html .= sprintf('<li>%s%s</li>', $n['message'], ($n['detail'] ? sprintf(' <span class="detail">%s</span>', $n['detail']) : ''));
					endforeach;
					$html .= '</ul></div>';
				endforeach;
				if ($clear):
					$_SESSION['notifications'] = array();
				endif;
				if($print) { print $html; } else { return $html; }
			endif;
			return '';
		}
		
		static function json($clear=true)
		{
			if (count($_SESSION['notifications'] ?? []) > 0):
				$json = json_encode($_SESSION['notifications']);
				if ($clear):
					$_SESSION['notifications'] = [];
				endif;
				return $json;
			endif;
			return '[]';
		}
	}
?>