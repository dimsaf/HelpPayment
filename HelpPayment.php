<?php
/**
 * Класс-хэлпер для модуля прием платежей module/mod_payment.php
 *
 * документация по яндекс кассе
 * https://yandex.ru/support/checkout/payments/api.html
 * https://kassa.yandex.ru/docs/guides/#bystryj-start
 * https://kassa.yandex.ru/docs/checkout-api/#ispol-zowanie-api
 * кабинет https://money.yandex.ru/my/tunes
 */

class HelpPayment
{
	/** @var int $shopId shop id магазина */
	static private $shopId = '';
	/** @var string $key секретный ключ(пароль) магазина */
	static private $key = '';
	/** @var int $realMode 1 - рабочий режим,  0 - тестовый режим */
	static public $realMode = 0;
	/** @var string $paymentUrl ссылка для отправки(создания) платежа */
	static private $paymentUrl = 'https://payment.yandex.net/api/v3/payments/';
	/** @var array $statusCode коды статусов платежа для записи в базу */
	static private $statusCode = ['pending' => 1, 'waiting_for_capture' => 2, 'succeeded' => 3, 'canceled' => 4];

	/**
	 * Установка учетных данных
	 *
	 * @param array $data массив данных для yandex кассы из таблицы akt_site
	 *
	 * @throws Exception
	 *
	 * @return void
	 */
	static private function setCredentials(array $data)
	{
		// если рабочий режим
		if ($data['yandex_kassa_real_mode']) {
			self::$shopId = $data['yandex_kassa_shop_id'];
			self::$key = $data['yandex_kassa_key'];
			// иначе тестовый режим
		} else {
			self::$shopId = $data['yandex_kassa_shop_id_test'];
			self::$key = $data['yandex_kassa_key_test'];
		}

		if (!self::$shopId || !self::$key) throw new Exception("Не удалось установить учетные данные.");
	}

	/**
	 * Создание и отправка платежа в яндекс кассу
	 *
	 * @param array $dataForm массив данных формы
	 * @param array $credentials массив учетных данных yandex кассы
	 *
	 * @throws Exception
	 *
	 * @return string
	 */
	static public function createPayment(array $dataForm, array $credentials)
	{
		// конвертируем в CP1251 и обрезаем пробелы
		$dataForm = array_map(function ($a) {
			return trim($a);
		}, convertToCp1251($dataForm));
		// форматируем фио
		foreach (['name', 'surname', 'patronym'] as $v) {
			$dataForm[$v] = ucfirst(strtolower($dataForm[$v]));
		}
		// форматируем сумму
		$dataForm['sum'] = number_format($dataForm['sum'], 2, '.', '');

		// валидация данных формы
		self::validateForm($dataForm);
		// установка учетных данных
		self::setCredentials($credentials);
		// создание платежа в яндекс кассе
		$res = self::sendPayment($dataForm);
		// вставка платежа в БД
		self::insertPayment($dataForm, $res);

		return $res;
	}

	/**
	 * Валидация данных формы
	 *
	 * @param array $dataForm массив данных формы
	 *
	 * @throws Exception
	 */
	static private function validateForm(array $dataForm)
	{
		if (!$dataForm['contract']) throw new Exception('Не задан номер договора.');
		if (!preg_match('/^[а-яА-ЯёЁ]+$/i', $dataForm['surname'])) throw new Exception('Фамилия должна состоять из русских букв.');
		if (!preg_match('/^[а-яА-ЯёЁ]+$/i', $dataForm['name'])) throw new Exception('Имя должно состоять из русских букв.');
		if (!preg_match('/^[а-яА-ЯёЁ]+$/i', $dataForm['patronym'])) throw new Exception('Отчество должно состоять из русских букв.');
		if (!is_numeric($dataForm['sum']) || $dataForm['sum'] < 0) throw new Exception('Сумма должна быть цифрой больше 0.');
		if (!filter_var($dataForm['email'], FILTER_VALIDATE_EMAIL)) throw new Exception('Электронная почта должна соответствовать формату адресов email. Пример правильного email: vasya@mail.ru');
	}

	/**
	 * Отправка платежа
	 *
	 * @param array $dataForm массив данных формы оплаты
	 *
	 * @throws Exception
	 *
	 * @return string
	 */
	static private function sendPayment(array $dataForm)
	{
		// Логин и пароль для аутентификации
		$user = self::$shopId;
		$pass = self::$key;

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => "$user:$pass",
			CURLOPT_HEADER => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => self::$paymentUrl,
			CURLOPT_POST => 1,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Idempotence-Key: {$dataForm['uniqid']}"],
			CURLOPT_POSTFIELDS => json_encode(convertToUtf8(self::createDataPaySend($dataForm)))
		]);
		// выполняем запрос
		$res = curl_exec($ch);
		// проверяем наличие ошибки и код ответа
		if (curl_errno($ch)) {
			throw new Exception(curl_error($ch));
		} else {
			if (!self::$realMode) file_put_contents(DOCUMENT_ROOT . '/module/log/yakassa_test.txt', convertToCp1251(print_r(json_decode($res), 1)), FILE_APPEND);
			switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
				case 200:  # OK
					break;
				case 202:  # сервер не успел обработать запрос https://kassa.yandex.ru/docs/checkout-api/#asinhronnost
					$res = json_decode($res);
					throw new Exception('Сервер яндекса не успел обработать запрос на оплату за отведенное время. Повторите отправку через ' . round($res->retry_after/1000) . 'сек.');
					break;
				default:
					$res = convertToCp1251($res);
					throw new Exception("Неожиданный код ответа: $http_code. Повторите отправку оплаты или обратитесь в техподдержку. $res");
			}
		}

		return $res;
	}

	/**
	 * Массив с данными для отправки платежа
	 * https://kassa.yandex.ru/docs/checkout-api/?php#sozdanie-platezha
	 *
	 * @param array $dataForm массив данных формы
	 *
	 * @return array
	 */
	static private function createDataPaySend(array $dataForm)
	{
		$fio = join(' ', [$dataForm['surname'], $dataForm['name'], $dataForm['patronym']]);
		$sum = $dataForm['sum'];
		$contract = $dataForm['contract'];
		$email = $dataForm['email'];
		/** @var string $scheme тип протокола 'https' или 'http' */
		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';

		$payment = [
			'amount' => [
				'value' => $sum,
				'currency' => 'RUB'
			],
			'description' => "Дог. № $contract. $fio",
			'receipt' => [
				'items' => [
					['description' => "Оплата по договору № $contract", 'quantity' => '1.000', 'amount' => ['value' => $sum, 'currency' => 'RUB'], 'vat_code' => 1]
				],
				'email' => $email
			],
			'confirmation' => [
				'type' => 'redirect',
				 // ссылка для возврата на сайт со страницы оплаты яндекс кассы после проведения платежа
				'return_url' => $scheme . '://' . SITE_DOMAIN
			],
			// сюда помещаем любые данные, которые мы хотим, чтобы яндекс нам передавал с каждым своим запросом
			'metadata' => [
				'surname' => $dataForm['surname'],
				'name' => $dataForm['name'],
				'patronym' => $dataForm['patronym'],
				'contract' => $contract,
				'email' => $email,
				'sum' => $sum,
				// ключ идемпотентности для вставки в запрос на подтверждение платежа https://kassa.yandex.ru/docs/checkout-api/#idempotentnost
				'iKeyCapture' => uniqid('', true)
			],
			// автоматическое подтверждение платежа
			'capture' => true
		];

		return $payment;
	}

	/**
	 * Вставка платежа в БД.
	 * пример ответа $res https://kassa.yandex.ru/docs/guides/#shag-2-perenapraw-te-pol-zowatelq-na-stranicu-w-yandex-kasse
	 *
	 * @param array $dataForm данные из формы платежа
	 * @param string $res результат запроса на создание платежа в яндекс кассе
	*/
	static private function insertPayment(array $dataForm, string $res)
	{
		$pay = convertToCp1251(json_decode($res, true));
		$name = $dataForm['name'];
		$surname = $dataForm['surname'];
		$patronym = $dataForm['patronym'];
		$sum = $dataForm['sum'];
		$contract = $dataForm['contract'];
		$email = $dataForm['email'];
		$pay_id = $pay['id'];
		$status = self::$statusCode[$pay['status']] ?? 0;
		$site_id = SITE_ID;

		mysql2_query("INSERT INTO akt_yakassa_pay (yakp_contract, yakp_sum, yakp_email, yakp_name, yakp_surname, yakp_patronym, yakp_create_dt, yakp_pay_id, yakp_status, yakp_site_id) VALUES ('$contract', $sum, '$email', '$name', '$surname', '$patronym', NOW(), '$pay_id', '$status', $site_id)");
	}

	/**
	 * Обработка уведомления о платеже от яндекс кассы
	 * https://kassa.yandex.ru/docs/guides/?php#uwedomleniq
	 *
	 * @param stdClass $notice уведомление
	 * @param array $credentials учетные данные якассы
	 *
	 * @throws Exception
	 *
	*/
	static public function processNotification(stdClass $notice, array $credentials)
	{
		if (empty($notice->event)) throw new Exception('Отсутствует свойство уведомления');
		if (empty($notice->object->id)) throw new Exception('Отсутствует id платежа');

		// установка учетных данных
		self::setCredentials($credentials);
		// получение платежа из БД
		$pay = self::findPay($notice->object->id);

		/**
		 * платеж перешел в статус waiting_for_capture("платеж завершен и ожидает ваших действий").
		 * Эта стадия нужна, скорее, для отмены платежа либо подтверждения частичной суммы.
		 * Пока проставил в отправке платежа capture=true (в методе createDataPaySend),
		 * чтобы ее пропустить и сразу приходило событие payment.succeeded
		*/
		if ($notice->event == 'payment.waiting_for_capture') {
			$status = 'waiting_for_capture';
			// Проверка платежа в яндекс кассе
			self::checkYaKassaPayment($pay, $status);
			// подтверждаем прием платежа
			self::sendConfirmPayment($notice);
			// здесь платеж успешно подтвержден, но еще не завершен. Можно что-то делать с ним, если требуется

		// платеж перешел в статус succeeded ("платеж успешно завершен").
		} elseif ($notice->event == 'payment.succeeded') {
			$status = 'succeeded';
			// Проверка платежа в яндекс кассе
			self::checkYaKassaPayment($pay, $status);
			/** @var array $metadata данные из формы платежа, заполняется в методе createDataPaySend */
			$metadata = convertToCp1251((array)$notice->object->metadata);
			// сумма платежа
			$sum = $pay->yakp_sum;
			// здесь платеж успешно завершен. Можно что-то делать с ним, если требуется

		} else {
			throw new Exception('Неизвестный тип уведомления');
		}

		// обновляем статус платежа в базе
		self::updateStatusPay($notice->object->id, $status);
	}

	/**
	 * Поиск платежа в базе
	 *
	 * @param string $id id платежа в yandex кассе
	 *
	 * @throws Exception
	 *
	 * @return object
	*/
	static private function findPay(string $id)
	{
		$site_id = SITE_ID;
		$id = mysql2_real_escape_string($id);
		$res = mysql2_query("SELECT * FROM akt_yakassa_pay WHERE yakp_pay_id='$id' AND yakp_site_id=$site_id");
		if (!mysql2_num_rows($res)) throw new Exception("Платеж с id $id не найден в базе.");

		return mysql2_fetch_object($res);
	}

	/**
	 * Проверка платежа в яндекс кассе
	 *
	 * @param stdClass $pay платеж из нашей БД
	 * @param string $state статус платежа в яндекс кассе
	 *
	 * @throws Exception
	 *
	 */
	static private function checkYaKassaPayment(stdClass $pay, string $state)
	{
		// Логин и пароль для аутентификации
		$user = self::$shopId;
		$pass = self::$key;
		$id = $pay->yakp_pay_id;

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => "$user:$pass",
			CURLOPT_HEADER => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 0,
			CURLOPT_URL => self::$paymentUrl . $id,
		]);
		// выполняем запрос
		$res = json_decode(curl_exec($ch));
		if (curl_errno($ch)) throw new Exception('Ошибка проверки статуса: ' . convertToCp1251(curl_error($ch)));
		if (empty($res)) throw new Exception('Платеж отсутствует в яндекс кассе.');

		/** @var array $hashData массив данных платежа в базе для сверки */
		$hashData = [$pay->yakp_name, $pay->yakp_surname, $pay->yakp_patronym, $pay->yakp_email, $pay->yakp_contract, $pay->yakp_sum, 'rub', $state];
		/** @var string $hash хэш платежа в базе */
		$hash = md5(join('', $hashData));

		/** @var object $md данные из формы платежа, заполняется в методе createDataPaySend */
		$md = $res->metadata;
		$sum = $res->amount->value;
		$currency = strtolower($res->amount->currency);
		/** @var array $hashYaData массив данных платежа в яндекс кассе для сверки */
		$hashYaData = convertToCp1251([$md->name, $md->surname, $md->patronym, $md->email, $md->contract, $sum, $currency, $res->status]);
		/** @var string $hashYa хэш платежа в яндекс кассе */
		$hashYa = md5(join('', $hashYaData));

		// сверка хэша данных платежа в базе с хэшем данных платежа в яндекс кассе
		if ($hash != $hashYa) throw new Exception("Хэши платежей не совпадают. " . print_r($hashData, 1) . ", " . print_r($hashYaData, 1));
	}

	/**
	 * Обновление статуса платежа в базе
	 *
	 * @param string $id id платежа в яндекс кассе
	 * @param string $status статус платежа в яндекс кассе
	 */
	static private function updateStatusPay(string $id, string $status)
	{
		$site_id = SITE_ID;
		$statusCode = self::$statusCode[$status];
		mysql2_query("UPDATE akt_yakassa_pay SET yakp_status=$statusCode, yakp_error='' WHERE yakp_pay_id='$id' AND yakp_site_id=$site_id");
	}

	/**
	 * Отправка подтверждения приема платежа
	 *
	 * @param stdClass $notice объект уведомления платежа от яндекс кассы
	 *
	 * @throws Exception
	 *
	 */
	static private function sendConfirmPayment(stdClass $notice)
	{
		// Логин и пароль для аутентификации
		$user = self::$shopId;
		$pass = self::$key;
		$idempotenceKey = $notice->object->metadata->iKeyCapture;

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => "$user:$pass",
			CURLOPT_HEADER => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => self::$paymentUrl . $notice->object->id . '/capture',
			CURLOPT_POST => 1,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Idempotence-Key: $idempotenceKey"],
		]);
		// выполняем запрос
		$res = curl_exec($ch);
		// проверяем наличие ошибки и код ответа
		if (curl_errno($ch)) {
			throw new Exception('Ошибка подтверждения: ' . convertToCp1251(curl_error($ch)));
		} else {
			$res = convertToCp1251(print_r(json_decode($res), 1));
			switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
				case 200:  # OK
					break;
				case 202:  # сервер не успел обработать запрос https://kassa.yandex.ru/docs/checkout-api/#asinhronnost
					// ошибкой здесь код 202 не считаем, так как он тоже говорит, что подтверждение получено, но пока не обработано
					break;
				default:
					throw new Exception("Неожиданный код ответа: $http_code. $res ");
			}
		}
	}

	/**
	 * Запись в базу ошибки при обработке уведомления
	 *
	 * @param string $id id платежа в яндекс кассе
	 * @param string $error текст ошибки
	 *
	 */
	static public function writeError(string $id, string $error)
	{
		$site_id = SITE_ID;
		$error = mysql2_real_escape_string($error);
		$id = mysql2_real_escape_string($id);
		mysql2_query("UPDATE akt_yakassa_pay SET yakp_error='$error' WHERE yakp_pay_id='$id' AND yakp_site_id=$site_id");
	}
}
