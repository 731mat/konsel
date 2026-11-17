<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Attendance;
use App\Model\Meeting\AttendanceEntry;
use App\Model\Meeting\AttendanceOverview;
use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingType;
use App\Model\Meeting\Quorum;
use App\Model\Meeting\Vote;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingResult;
use App\Model\Meeting\VotingRound;
use App\Model\Meeting\VotingType;
use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityProfile;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\MemberFunction;
use App\Model\People\Membership;
use App\Model\People\Person;
use App\Model\People\PersonName;
use App\Model\People\Term;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionStatus;
use App\Model\Shared\ValueObject\Email;
use App\Model\Shared\ValueObject\Ico;
use Latte\Essential\CachingIterator;
use Latte\Sandbox\SecurityPolicy;

/**
 * Co smí šablona dokumentu (i vlastní šablona obce): jen číst údaje zasedání přes getry a formátovat je.
 * Žádné volání PHP funkcí s callbackem, žádné soubory, žádné metody měnící data, žádné odkazy na uživatele úřadu.
 */
final class DocumentSandboxPolicy
{
	/**
	 * Objekty, které se do šablon dokumentů dostanou (a jejich getry).
	 */
	public const array Classes = [
		Meeting::class, AgendaItem::class, Body::class, Term::class, Person::class, PersonName::class, Membership::class,
		Municipality::class, MunicipalityProfile::class, Email::class, Ico::class,
		Resolution::class, VotingRound::class, Vote::class,
		AttendanceOverview::class, AttendanceEntry::class, Attendance::class, Quorum::class,
		MeetingStatus::class, MeetingType::class, BodyType::class, MemberFunction::class, ResolutionStatus::class,
		VoteChoice::class, VotingType::class, VotingResult::class, AttendanceStatus::class,
	];

	/**
	 * Hodnotové objekty – veřejné (readonly) vlastnosti jsou jen data.
	 */
	public const array ValueObjects = [
		MunicipalityProfile::class, Email::class, Ico::class, AttendanceOverview::class, AttendanceEntry::class, Quorum::class,
	];

	/**
	 * Metody, které jen čtou: getry, is/has/can a popisky číselníků.
	 */
	private const string ReadMethodPattern = '~^(get|is|has|can|count|label|tone|icon|description|quorumPhrase|sortable|withStatus|withMovements|votesWith|present)~i';

	/**
	 * Co šabloně nedáme ani jako getr (soubory v úložišti, verze, obec mimo zasedání).
	 */
	private const array DeniedMethods = ['getLogo', 'getVersion', 'getBrandColors', 'getSlug', 'getAuthorUser'];

	/**
	 * Bezpečné PHP funkce bez callbacků (pole, texty, čísla).
	 */
	private const array Functions = [
		'count', 'implode', 'join', 'trim', 'rtrim', 'ltrim', 'in_array', 'array_keys', 'array_values', 'array_slice',
		'array_reverse', 'array_merge', 'array_sum', 'array_key_exists', 'min', 'max', 'abs', 'round', 'number_format',
		'sprintf', 'str_repeat', 'str_pad', 'str_contains', 'mb_strtoupper', 'mb_strtolower', 'mb_substr', 'mb_strlen',
		'ucfirst', 'lcfirst', 'nl2br', 'range',
	];

	/**
	 * Filtry aplikace navíc k bezpečným filtrům Latte.
	 */
	private const array Filters = ['czDate', 'czDateLong', 'czDateTime', 'czMonth', 'genitiveBody', 'initials', 'nl2p', 'names', 'period', 'where'];

	public static function create(): SecurityPolicy
	{
		$policy = SecurityPolicy::createSafePolicy();
		// layout a include načítají jen šablony dokumentů (DocumentTemplateLoader nic jiného nezná)
		$policy->allowTags(['layout', 'include', 'parameters', 'block', 'define']);
		$policy->allowFilters(self::Filters);
		$policy->allowFunctions(self::Functions);
		$policy->allowMethods(CachingIterator::class, SecurityPolicy::All);
		$policy->allowMethods(\DateTimeInterface::class, ['format', 'getTimestamp']);

		foreach (self::Classes as $class) {
			$policy->allowMethods($class, self::readMethods($class));
		}

		foreach (self::ValueObjects as $class) {
			$policy->allowProperties($class, SecurityPolicy::All);
		}

		$policy->allowProperties(\UnitEnum::class, ['name', 'value']);

		return $policy;
	}

	/**
	 * @param class-string $class
	 * @return list<string>
	 */
	public static function readMethods(string $class): array
	{
		$methods = [];
		foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$name = $method->getName();
			if ($method->isStatic() || $method->isConstructor() || in_array($name, self::DeniedMethods, true) || preg_match(self::ReadMethodPattern, $name) !== 1) {
				continue;
			}

			$methods[] = $name;
		}

		// číselníky: seznam hodnot a převod z textu jsou jen čtení
		if (enum_exists($class)) {
			array_push($methods, 'cases', 'from', 'tryFrom');
		}

		return $methods;
	}
}
