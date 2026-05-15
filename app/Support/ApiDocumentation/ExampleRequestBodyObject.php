<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\Support\Generator\Example;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;

final class ExampleRequestBodyObject extends RequestBodyObject
{
    /** @var array<string, mixed> */
    private array $contentExamples = [];

    /** @var array<string, array<string, Example|Reference>> */
    private array $contentNamedExamples = [];

    public static function fromRequestBodyObject(RequestBodyObject $requestBodyObject): self
    {
        $exampleRequestBodyObject = new self;
        $exampleRequestBodyObject->description = $requestBodyObject->description;
        $exampleRequestBodyObject->content = $requestBodyObject->content;
        $exampleRequestBodyObject->required = $requestBodyObject->required;

        if ($requestBodyObject instanceof self) {
            $exampleRequestBodyObject->contentExamples = $requestBodyObject->contentExamples;
            $exampleRequestBodyObject->contentNamedExamples = $requestBodyObject->contentNamedExamples;
        }

        return $exampleRequestBodyObject;
    }

    public function setContentExample(string $mediaType, mixed $example): self
    {
        $this->contentExamples[$mediaType] = $example;

        return $this;
    }

    /**
     * @param  array<string, Example|Reference>  $examples
     */
    public function setContentExamples(string $mediaType, array $examples): self
    {
        $this->contentNamedExamples[$mediaType] = $examples;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $result = array_filter([
            'description' => $this->description,
            'required' => $this->required,
        ]);

        $content = [];

        foreach ($this->content as $mediaType => $schema) {
            $entry = [
                'schema' => $schema->toArray(),
            ];

            if (array_key_exists($mediaType, $this->contentExamples)) {
                $entry['example'] = $this->contentExamples[$mediaType];
            }

            if (array_key_exists($mediaType, $this->contentNamedExamples)) {
                $examples = [];

                foreach ($this->contentNamedExamples[$mediaType] as $key => $example) {
                    $examples[$key] = $example->toArray();
                }

                if ($examples !== []) {
                    $entry['examples'] = $examples;
                }
            }

            $content[$mediaType] = $entry;
        }

        $result['content'] = $content;

        return $result;
    }
}
