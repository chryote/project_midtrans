<?php

namespace Tests\Unit;

use App\Traits\ApiResponse;
use PHPUnit\Framework\TestCase;

class ApiResponseTraitTest extends TestCase
{
    private object $classUsingTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classUsingTrait = new class {
            use ApiResponse;

            public function testSuccess($data = null, string $message = 'Success', int $code = 200)
            {
                return $this->successResponse($data, $message, $code);
            }

            public function testError(string $message = 'Error', int $code = 400, $errors = null)
            {
                return $this->errorResponse($message, $code, $errors);
            }
        };
    }

    public function test_success_response_structure(): void
    {
        $response = $this->classUsingTrait->testSuccess(['item' => 'test'], 'Data retrieved', 200);

        $this->assertEquals(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertEquals('success', $data['status']);
        $this->assertEquals(200, $data['code']);
        $this->assertEquals('Data retrieved', $data['message']);
        $this->assertEquals(['item' => 'test'], $data['data']);
    }

    public function test_error_response_structure(): void
    {
        $response = $this->classUsingTrait->testError('Invalid input', 422, ['field' => 'Required']);

        $this->assertEquals(422, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertEquals('error', $data['status']);
        $this->assertEquals(422, $data['code']);
        $this->assertEquals('Invalid input', $data['message']);
        $this->assertEquals(['field' => 'Required'], $data['errors']);
    }
}
