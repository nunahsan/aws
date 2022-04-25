<?php

namespace Nunahsan\Aws;

use Aws\Sts\StsClient;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\S3\PostObjectV4;

class Sts extends \Illuminate\Support\ServiceProvider {

    public function boot() {
        
    }

    public function register() {
        
    }
    
    public static function getS3ClientFromAssumeRole() {
        //prepare sts config
        $stsClient = new StsClient([
            'region' => env('AWS_REGION'),
            'version' => 'latest',
            'credentials' => new Credentials(env('AWS_S3_ACCESS_KEY'), env('AWS_S3_SECRET_KEY'))
        ]);

        //create assume role
        $assumeRes = $stsClient->AssumeRole([
            'RoleArn' => env('AWS_S3_ROLE_ARN'),
            'RoleSessionName' => 'AssumeSession',
            'DurationSeconds' => env('AWS_S3_ASSUME_EXPIRY')
        ]);
        if (!isset($assumeRes['Credentials'])) {
            abort(503, json_encode(['name' => 'S3-Assume-Role', 'result' => $assumeRes]));
        }
        $assume = $assumeRes['Credentials'];

        //create s3 client
        $s3client = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_REGION'),
            'credentials' => new Credentials($assume['AccessKeyId'], $assume['SecretAccessKey'], $assume['SessionToken'], env('AWS_S3_CLIENT_EXPIRY'))
        ]);

        return $s3client;
    }

    public static function createUploadProperty($fileName, $isPublic = false, $contentType, $minSize = 1024, $maxSize = 5242880) {
        //prepare user parameter
        $bucket = $isPublic ? env('AWS_S3_BUCKET_NAME_PUBLIC') : env('AWS_S3_BUCKET_NAME_PRIVATE');
        $target = self::fileDestination($fileName);
        //private or public still need acl => private
        $acl = ['acl' => 'private'];
        $options = [
            $acl,
            ['bucket' => $bucket],
            ['eq', '$key', $target],
            ["content-length-range", $minSize, $maxSize],
            ['content-type' => $contentType]
        ];
        $expiry = '+' . env('AWS_S3_CLIENT_EXPIRY') . ' seconds';

        //generate post object
        $postObject = new PostObjectV4(self::getS3ClientFromAssumeRole(), $bucket, $acl, $options, $expiry);

        $formAttributes = $postObject->getFormAttributes();
        $formInputs = $postObject->getFormInputs();
        $formInputs['key'] = $target;

        //return to caller
        return [
            'data' => [
                'body' => $formAttributes,
                'field' => $formInputs
            ]
        ];
    }

    public static function getPresignUrl($s3client, $fileFullPath) {
        $cmd = $s3client->getCommand('GetObject', [
            'Bucket' => env('AWS_S3_BUCKET_NAME_PRIVATE'),
            'Key' => $fileFullPath
        ]);
        $request = $s3client->createPresignedRequest($cmd, '+' . env('AWS_S3_PRESIGN_EXPIRY') . ' seconds');
        $presign = (string) $request->getUri();
        return $presign;
    }

    protected static function fileDestination($fileName) {
        $unixPrefix = time() . '_' . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . '_';
        return env('APP_ENV') . '/' . date('Y') . '/' . date('m') . '/' . $unixPrefix . $fileName;
    }

}
