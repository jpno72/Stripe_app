<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
final class StripeSecurity
{
    public static function client(array $config): \Stripe\StripeClient
    {
        $key=(string)($config['STRIPE_API_KEY']??'');if(!preg_match('/\Ark_(live|test)_[A-Za-z0-9]+\z/D',$key)||str_contains($key,'REPLACE'))throw new RuntimeException('Configure a restricted Stripe API key.');return new \Stripe\StripeClient(['api_key'=>$key]);
    }
    public static function verifyRequest(array $config): \Stripe\Event
    {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Webhook requires POST.');
        $body=file_get_contents('php://input',false,null,0,1048577);if($body===false||strlen($body)>1048576)throw new RuntimeException('Invalid webhook body.');
        return self::verify($body,(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??''),$config);
    }
    public static function verify(string $body,string $signature,array $config): \Stripe\Event
    {
        $secret=(string)($config['STRIPE_WEBHOOK_SECRET']??'');if(!preg_match('/\Awhsec_[A-Za-z0-9]+\z/D',$secret)||str_contains($secret,'REPLACE'))throw new RuntimeException('Webhook endpoint secret is not configured.');
        return \Stripe\Webhook::constructEvent($body,$signature,$secret,300);
    }
}
