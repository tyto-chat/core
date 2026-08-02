<?php

declare(strict_types=1);

namespace App\Enum\Moderation;

enum ReportCategory: string
{
    case IllegalContent = 'illegal_content';
    case Harassment = 'harassment';
    case Spam = 'spam';
    case RuleViolation = 'rule_violation';
    case Other = 'other';
}
