<?php

namespace Bambora;

class PayFormWPConnector implements PayFormConnector
{
	public function request($url, $post_arr)
	{
		$response = wp_remote_post(PayForm::API_URL . "/" . $url, array(
			'sslverify' => true,
			'timeout' => 60,
			'headers' => array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen(json_encode($post_arr))
			),
			'body' => json_encode($post_arr)
		));

		if(is_wp_error($response))
		{
			$error_message = $response->get_error_message();
			throw new PayFormException('PayFormWPConnector::request - error: ' . $error_message);
		}

		return wp_remote_retrieve_body($response);
	}
}