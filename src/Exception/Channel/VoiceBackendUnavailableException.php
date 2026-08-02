<?php

declare(strict_types=1);

namespace App\Exception\Channel;

/** Not a DomainException — callers must treat it as "state unknown", never as "room is empty". */
class VoiceBackendUnavailableException extends \RuntimeException
{
}
