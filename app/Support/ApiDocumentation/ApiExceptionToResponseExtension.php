<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use App\Support\Api\ApiResponseFactory;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\LengthRequiredHttpException;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class ApiExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(\Throwable::class);
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        $status = $this->statusFor($type);

        return Response::make($status)
            ->setDescription(ApiResponseFactory::messageForStatus($status))
            ->setContent(
                'application/json',
                Schema::fromType($status === 422 ? $this->validationBodyType() : $this->errorBodyType($status)),
            );
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }

    private function statusFor(ObjectType $type): int
    {
        if ($type->isInstanceOf(ValidationException::class)) {
            return 422;
        }

        if ($type->isInstanceOf(AuthenticationException::class)) {
            return 401;
        }

        if ($type->isInstanceOf(AuthorizationException::class)) {
            return 403;
        }

        if ($type->isInstanceOf(RecordsNotFoundException::class) || $type->isInstanceOf(NotFoundHttpException::class)) {
            return 404;
        }

        if ($type->isInstanceOf(HttpException::class)) {
            $codeType = count($type->templateTypes ?? []) > 3
                ? ($type->templateTypes[7] ?? null)
                : ($type->templateTypes[0] ?? null);

            if ($codeType instanceof LiteralIntegerType) {
                return $codeType->value;
            }

            return match (true) {
                $type->isInstanceOf(AccessDeniedHttpException::class) => 403,
                $type->isInstanceOf(BadRequestHttpException::class) => 400,
                $type->isInstanceOf(ConflictHttpException::class) => 409,
                $type->isInstanceOf(GoneHttpException::class) => 410,
                $type->isInstanceOf(LengthRequiredHttpException::class) => 411,
                $type->isInstanceOf(LockedHttpException::class) => 423,
                $type->isInstanceOf(MethodNotAllowedHttpException::class) => 405,
                $type->isInstanceOf(NotAcceptableHttpException::class) => 406,
                $type->isInstanceOf(PreconditionFailedHttpException::class) => 412,
                $type->isInstanceOf(PreconditionRequiredHttpException::class) => 428,
                $type->isInstanceOf(ServiceUnavailableHttpException::class) => 503,
                $type->isInstanceOf(TooManyRequestsHttpException::class) => 429,
                $type->isInstanceOf(UnauthorizedHttpException::class) => 401,
                $type->isInstanceOf(UnprocessableEntityHttpException::class) => 422,
                $type->isInstanceOf(UnsupportedMediaTypeHttpException::class) => 415,
                default => 500,
            };
        }

        return 500;
    }

    private function errorBodyType(int $status): OpenApiTypes\ObjectType
    {
        return (new OpenApiTypes\ObjectType)
            ->addProperty(
                'message',
                (new OpenApiTypes\StringType)
                    ->setDescription('Human-readable error summary.')
                    ->example(ApiResponseFactory::messageForStatus($status)),
            )
            ->addProperty('error', $this->errorObjectType($status))
            ->addProperty('meta', $this->metaObjectType())
            ->setRequired(['message', 'error', 'meta']);
    }

    private function validationBodyType(): OpenApiTypes\ObjectType
    {
        $fieldErrors = (new OpenApiTypes\ObjectType)
            ->setDescription('Field-level validation errors keyed by request attribute.')
            ->additionalProperties((new OpenApiTypes\ArrayType)->setItems(new OpenApiTypes\StringType));

        $errorDetails = (new OpenApiTypes\ObjectType)
            ->addProperty('fields', $fieldErrors)
            ->setRequired(['fields']);

        $error = $this->errorObjectType(422)
            ->addProperty('details', $errorDetails)
            ->setRequired(['code', 'message', 'details']);

        return (new OpenApiTypes\ObjectType)
            ->addProperty(
                'message',
                (new OpenApiTypes\StringType)
                    ->setDescription('Human-readable validation summary.')
                    ->example(ApiResponseFactory::messageForStatus(422)),
            )
            ->addProperty('errors', $fieldErrors)
            ->addProperty('error', $error)
            ->addProperty('meta', $this->metaObjectType())
            ->setRequired(['message', 'errors', 'error', 'meta']);
    }

    private function errorObjectType(int $status): OpenApiTypes\ObjectType
    {
        return (new OpenApiTypes\ObjectType)
            ->addProperty(
                'code',
                (new OpenApiTypes\StringType)
                    ->setDescription('Stable machine-readable error code.')
                    ->example(ApiResponseFactory::errorCodeForStatus($status)),
            )
            ->addProperty(
                'message',
                (new OpenApiTypes\StringType)
                    ->setDescription('Human-readable error summary.')
                    ->example(ApiResponseFactory::messageForStatus($status)),
            )
            ->setRequired(['code', 'message']);
    }

    private function metaObjectType(): OpenApiTypes\ObjectType
    {
        return (new OpenApiTypes\ObjectType)
            ->addProperty(
                'request_id',
                (new OpenApiTypes\StringType)
                    ->setDescription('Request correlation ID for support and logs.'),
            )
            ->setRequired(['request_id']);
    }
}
