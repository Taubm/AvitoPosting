<?php

/**
 * ContactForm class.
 * ContactForm is the data structure for keeping
 * contact form data. It is used by the 'contact' action of 'SiteController'.
 */
class AvitoPostForm extends CFormModel
{
	public $email;
	public $phone;
	public $body;
	public $name;

	/**
	 * Declares the validation rules.
	 */
	public function rules()
	{
		return array(
			// name, email, phone and body are required
			array('name, email, phone, body', 'required'),
			// email has to be a valid email address
			array('email', 'email'),
			// phone number is numerical
			array('phone', 'numerical'),
			// phone number length = 11
			array('phone', 'length', 'min'=>11, 'max'=>11)
		);
	}

	public function post()
	{
		$result = ['error'=>'', 'link'=>''];
		// Получаем куки
		$cookies = $this->getCookies();

		// Если получили меньше 3 кук - возможно, изменился алгоритм работы сайта
		if (count($cookies)<3) 
		{
			$result['error'] = 'Не удалось разместить объявление. Возможно, изменился алгоритм размещения объявления.';
			return $result;
		}

		// Размещаем объявление и получаем страницу с капчей
		$data = $this->addItem($cookies);
		
		// Извлекаем номер капчи, сохраняем в файл для отправки на антигейт
		preg_match('/captcha\?[0-9]*/', $data, $captcha);

		// Если в ответе нет капчи, то изменился алгоритм или аккаунт забанен
		if (empty($captcha)) 
		{
			$result['error'] = 'Не удалось получить капчу. Возможно, изменился алгоритм размещения объявления или аккаунт с указанным email забанен.';
			return $result;
		}
		$captcha=$captcha[0];
		$this->save_image($captcha, 'images/captcha.jpeg', $cookies);

		// Распознаем капчу
		$captchaText=$this->recognize(dirname(Yii::app()->basePath) . "\images\captcha.jpeg");

		if ($captchaText==false)
		{
			$result['error'] = 'Не удалось распознать капчу, попробуйте позже.';
			return $result;
		}

		// Форма для зарегистрированных и незарегистрированных email отличается, учитываем это
		$mode = 'exist';
		if (stripos($data, 'password-confirm-field')!=false) {
			$mode = 'new';
		}

		// Отсылаем подтверждение размещения
		$data = $this->confirm($cookies, $captchaText, $mode);

		// Отдаем ссылку на размещенное объявление
		preg_match('/<div class=\"b-content b-content_payment-finish\"[\s\S]*?<\/div>/', $data, $m);
		
		// Если в ответе не содержится ссылки на объявление, то переданные данные были неверны
		if (empty($m)) 
		{
			$result['error'] = 'Не удалось разместить объявление. Переданные данные неверны.';
			return $result;
		}
		$result['link'] = str_replace('href="/', 'href="http://www.avito.ru/', $m[0]);
		return $result;
	}

	// Распознаем капчу
	private function recognize(
	            $filename,
	            $apikey='44bb304bb17425d35c79c71e5dfc7584',
	            $is_verbose = false,
	            $domain="antigate.com",
	            $rtimeout = 5,
	            $mtimeout = 120,
	            $is_phrase = 0,
	            $is_regsense = 0,
	            $is_numeric = 0,
	            $min_len = 0,
	            $max_len = 0,
	            $is_russian = 0
	            )
	{
		if (!file_exists($filename))
		{
			if ($is_verbose) echo "file $filename not found\n";
			return false;
		}
	    $postdata = array(
	        'method'    => 'post', 
	        'key'       => $apikey, 
	        'file'      => '@'.$filename,
	        'phrase'	=> $is_phrase,
	        'regsense'	=> $is_regsense,
	        'numeric'	=> $is_numeric,
	        'min_len'	=> $min_len,
	        'max_len'	=> $max_len,
		'is_russian'	=> $is_russian
	        
	    );
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL,             "http://$domain/in.php");
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER,     1);
	    curl_setopt($ch, CURLOPT_TIMEOUT,             60);
	    curl_setopt($ch, CURLOPT_POST,                 1);
	    curl_setopt($ch, CURLOPT_POSTFIELDS,         $postdata);
	    $result = curl_exec($ch);
	    if (curl_errno($ch)) 
	    {
	    	if ($is_verbose) echo "CURL returned error: ".curl_error($ch)."\n";
	        return false;
	    }
	    curl_close($ch);
	    if (strpos($result, "ERROR")!==false)
	    {
	    	if ($is_verbose) echo "server returned error: $result\n";
	        return false;
	    }
	    else
	    {
	        $ex = explode("|", $result);
	        $captcha_id = $ex[1];
	    	if ($is_verbose) echo "captcha sent, got captcha ID $captcha_id\n";
	        $waittime = 0;
	        if ($is_verbose) echo "waiting for $rtimeout seconds\n";
	        sleep($rtimeout);
	        while(true)
	        {
	            $result = file_get_contents("http://$domain/res.php?key=".$apikey.'&action=get&id='.$captcha_id);
	            if (strpos($result, 'ERROR')!==false)
	            {
	            	if ($is_verbose) echo "server returned error: $result\n";
	                return false;
	            }
	            if ($result=="CAPCHA_NOT_READY")
	            {
	            	if ($is_verbose) echo "captcha is not ready yet\n";
	            	$waittime += $rtimeout;
	            	if ($waittime>$mtimeout) 
	            	{
	            		if ($is_verbose) echo "timelimit ($mtimeout) hit\n";
	            		break;
	            	}
	        		if ($is_verbose) echo "waiting for $rtimeout seconds\n";
	            	sleep($rtimeout);
	            }
	            else
	            {
	            	$ex = explode('|', $result);
	            	if (trim($ex[0])=='OK') return trim($ex[1]);
	            }
	        }
	        
	        return false;
	    }
	}

	// Получаем куки для дальнейших запросов
	private function getCookies()
	{
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, 'http://www.avito.ru/additem');
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3");
		curl_setopt($ch, CURLOPT_HEADER, 1);

		$result = curl_exec($ch);

		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $m);

		$cookies[0] = explode('=', $m[0][0])[1];
		$cookies[1] = explode('=', $m[0][1])[1];
		$cookies[2] = explode('=', $m[0][2])[1];

		return $cookies;
	}

	// Первоначальное размещение объявления, возвращаем страницу-результат
	private function addItem($cookies)
	{
		$headers = array(
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            "Accept: application/json, text/javascript, */*; q=0.01",
            'X-Requested-With: XMLHttpRequest',
        ); 

        $ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'http://www.avito.ru/additem');
		curl_setopt($ch, CURLOPT_POSTFIELDS,
			'private=1&seller_name=' . $this->name . 
			'&manager=&email=' . $this->email . 
			'&phone=' . $this->phone . 
			'&location_id=633540' . 
			'&metro_id=' . 
			'&district_id=359' . 
			'&category_id=24' . 
			'&params%5B201%5D=1060' . 
			'&params%5B550%5D=5702' . 
			'&params%5B504%5D=5256' . 
			'&params%5B501%5D=5151' . 
			'&params%5B502%5D=5213' . 
			'&params%5B503%5D=5249' . 
			'&params%5B500%5D=10' . 
			'&params%5B493%5D=%D0%9B%D0%B5%D0%BD%D0%B8%D0%BD%D0%B0' . 
			'&coords%5Blat%5D=56.359928' . 
			'&coords%5Blng%5D=44.048998' . 
			'&coords%5Bzoom%5D=14' . 
			'&title=' . 
			'&description='.$this->body.
			'&price=0' . 
			'&service_code=free'
			);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_COOKIE, 'sessid=' . $cookies[0] . '; u=' . $cookies[1] . '; v=' . $cookies[2]. ';');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3");
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		$data = curl_exec($ch);

		return $data;
	}

	// Подтверждаем размещение, возвращаем страницу-результат
	private function confirm($cookies, $captcha, $mode)
	{
		$headers = array(
		    'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
		    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
		    "Referer: http://www.avito.ru/additem/confirm"
		    ); 
		
		$postFields = 'password=' . $this->email .
		'&captcha='.$captcha .
		'&subscribe-position=0' . 
		'&done=Далее >';

		if ($mode=='new') {
			$postFields .='&confirm=' . $this->email . 
			'&user_agree=1';
		}

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'http://www.avito.ru/additem/confirm');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_COOKIE, 'sessid=' . $cookies[0] . '; u=' . $cookies[1] . '; v=' . $cookies[2]. ';');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3");
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		$data = curl_exec($ch);

		return $data;
	}

	// Сохранение капчи локально
	function save_image($captcha, $path, $cookies){
		$headers = array(
            "Accept: image/png,image/*;q=0.8,*/*;q=0.5",
            "Referer: http://www.avito.ru/additem/confirm"
        ); 
		$ch = curl_init('http://www.avito.ru/' . $captcha);

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER,1);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3");
		curl_setopt($ch, CURLOPT_COOKIE, 'sessid=' . $cookies[0] . '; u=' . $cookies[1] . '; v=' . $cookies[2]. ';');

		$data = curl_exec($ch);
		curl_close($ch);
		if (file_exists($path)) :
	    	unlink($path);
		endif;
		$fp = fopen($path,'x');
		fwrite($fp, $data);
		fclose($fp);
	}

}