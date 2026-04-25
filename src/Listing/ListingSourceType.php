<?php

declare(strict_types=1);

namespace App\Listing;

enum ListingSourceType: string
{
    case HtmlSection = 'html_section';
    case RssFeed = 'rss_feed';
}
