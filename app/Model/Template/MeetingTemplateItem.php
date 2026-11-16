<?php

declare(strict_types=1);

namespace App\Model\Template;

final readonly class MeetingTemplateItem
{
	public function __construct(
		public string $title,
		public ?string $description = null,
		public ?string $proposedResolution = null,
	) {}

	/**
	 * @param array<mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$text = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

		return new self(is_string($data['title'] ?? null) ? $data['title'] : '', $text($data['description'] ?? null), $text($data['proposedResolution'] ?? null));
	}

	/**
	 * @return array{title: string, description: ?string, proposedResolution: ?string}
	 */
	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'proposedResolution' => $this->proposedResolution,
		];
	}
}
