<?php

declare(strict_types=1);

class Acme_Shop_Block_View
{
    public function render(string $name, string $dynamic): string
    {
        $html = __('Add to Cart');
        $html .= __('Home page');
        $html .= __('Hello %s', $name);
        $html .= __($dynamic);
        return $html;
    }
}
