<?php

namespace App\Enums;

enum AccountColor: string
{
    case Amber = 'amber';
    case Emerald = 'emerald';
    case Sky = 'sky';
    case Violet = 'violet';
    case Rose = 'rose';
    case Orange = 'orange';
    case Teal = 'teal';
    case Slate = 'slate';

    public function label(): string
    {
        return match ($this) {
            self::Amber => __('Gold'),
            self::Emerald => __('Green'),
            self::Sky => __('Blue'),
            self::Violet => __('Purple'),
            self::Rose => __('Pink'),
            self::Orange => __('Orange'),
            self::Teal => __('Teal'),
            self::Slate => __('Gray'),
        };
    }

    /** Fundo sólido (bolinhas e marcadores). */
    public function solid(): string
    {
        return match ($this) {
            self::Amber => 'bg-amber-500',
            self::Emerald => 'bg-emerald-500',
            self::Sky => 'bg-sky-500',
            self::Violet => 'bg-violet-500',
            self::Rose => 'bg-rose-500',
            self::Orange => 'bg-orange-500',
            self::Teal => 'bg-teal-500',
            self::Slate => 'bg-slate-500',
        };
    }

    /** Degradê usado no círculo da conta e no cartão. */
    public function gradient(): string
    {
        return match ($this) {
            self::Amber => 'from-amber-400 to-amber-700',
            self::Emerald => 'from-emerald-400 to-emerald-700',
            self::Sky => 'from-sky-400 to-sky-700',
            self::Violet => 'from-violet-400 to-violet-700',
            self::Rose => 'from-rose-400 to-rose-700',
            self::Orange => 'from-orange-400 to-orange-700',
            self::Teal => 'from-teal-400 to-teal-700',
            self::Slate => 'from-slate-400 to-slate-700',
        };
    }

    /** Brilho suave de fundo atrás do destaque. */
    public function glow(): string
    {
        return match ($this) {
            self::Amber => 'bg-amber-500/15',
            self::Emerald => 'bg-emerald-500/15',
            self::Sky => 'bg-sky-500/15',
            self::Violet => 'bg-violet-500/15',
            self::Rose => 'bg-rose-500/15',
            self::Orange => 'bg-orange-500/15',
            self::Teal => 'bg-teal-500/15',
            self::Slate => 'bg-slate-500/15',
        };
    }
}
