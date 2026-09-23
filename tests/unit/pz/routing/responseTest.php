<?php

declare(strict_types=1);

use pz\Enums\Routing\ResponseCode;
use pz\Routing\Response;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class responseTest extends TestCase
{
    public static function validResponseSignaturesProvider(): array
    {
        return [
            [
                'success' => true,
                'code' => ResponseCode::Ok,
                'answer' => 'Success',
                'redirect' => null,
                'data' => null,
                'data_messages' => null,
                'expected_redirect' => 'Location: ?success=true',
            ],
            [
                'success' => false,
                'code' => ResponseCode::BadRequestContent,
                'answer' => 'this is bad',
                'redirect' => null,
                'data' => ['key1' => 'value1'],
                'data_messages' => ['message1'],
                'expected_redirect' => 'Location: ?error=this is bad',
            ],
            [
                'success' => true,
                'code' => ResponseCode::Ok,
                'answer' => null,
                'redirect' => '/success',
                'data' => ['key2' => 'value2'],
                'data_messages' => ['key2' => 'message2'],
                'expected_redirect' => 'Location: /success?success=true',
            ],
        ];
    }

    #[DataProvider('validResponseSignaturesProvider')]
    public function testValidResponseSignatures(
        bool $success,
        ResponseCode $code,
        ?string $answer,
        ?string $redirect,
        ?array $data,
        ?array $data_messages,
        string $expected_redirect,
    ): void {
        $response = new Response($success, $code, $answer, $redirect, $data, $data_messages);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($success, $response->isSuccessful());
        $this->assertEquals($code, $response->getResponseCode());
        $this->assertEquals($answer, $response->answer);
        $this->assertEquals($expected_redirect, $response->getRedirect());
        $this->assertEquals($data ?? [], $response->data());
        $this->assertEquals($data_messages, $response->dataMessages());
    }

    public function testSetAnswerOverwrittesInitialAnswer(): void
    {
        $response = new Response(true, ResponseCode::Ok, 'Initial Answer');
        $response->setAnswer('New Answer');
        $this->assertEquals('New Answer', $response->answer);
    }

    public function testIsSuccessfull(): void
    {
        $response = new Response(true, ResponseCode::Ok);
        $this->assertTrue($response->isSuccessful());

        $response = new Response(false, ResponseCode::BadRequestContent);
        $this->assertFalse($response->isSuccessful());

        $response = new Response(false, ResponseCode::Ok);
    }

    public function testSetRedirect(): void
    {
        $response = new Response(true, ResponseCode::Ok);
        $response->setRedirect('/new-location');
        $this->assertEquals('Location: /new-location?success=true', $response->getRedirect());

        $response->setRedirect(null);
        $this->assertEquals('Location: ?success=true', $response->getRedirect());
    }

}
