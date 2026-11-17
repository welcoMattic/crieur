<?php

declare(strict_types=1);

namespace App\Text;

enum BlockKind: string
{
    case Title = 'title';
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Quote = 'quote';
    case ListItem = 'list_item';
}
