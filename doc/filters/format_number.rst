``format_number``
=================

The ``format_number`` filter formats a number:

.. code-block:: twig

    {{ '12.345'|format_number }}

You can pass attributes to tweak the output:

.. code-block:: twig

    {# 12.34 #}
    {{ '12.345'|format_number({rounding_mode: 'floor'}) }}

    {# 1000000.0000 #}
    {{ '1000000'|format_number({fraction_digit: 4}) }}

The list of supported options:

* ``grouping_used``: Specifies whether to use grouping separator for thousands::

        {# 1,234,567.89 #}
        {{ 1234567.89|format_number({grouping_used:true}, locale: 'en') }}

* ``decimal_always_shown``: Specifies whether to always show the decimal part, even if it's zero::

        {# 123. #}
        {{ 123|format_number({decimal_always_shown:true}, locale: 'en') }}

* ``max_integer_digit``:
* ``min_integer_digit``:
* ``integer_digit``: Define constraints on the integer part::

        {# 345.679 #}
        {{ 12345.6789|format_number({max_integer_digit:3, min_integer_digit:2}, locale: 'en') }}

* ``max_fraction_digit``:
* ``min_fraction_digit``:
* ``fraction_digit``: Define constraints on the fraction part::

        {# 123.46 #}
        {{ 123.456789|format_number({max_fraction_digit:2, min_fraction_digit:1}, locale: 'en') }}

* ``multiplier``: Multiplies the value before formatting::

        {# 123,000 #}
        {{ 123|format_number({multiplier:1000}, locale: 'en') }}

* ``grouping_size``:
* ``secondary_grouping_size``: Set the size of the primary and secondary grouping separators::

        {# 1,23,45,678 #}
        {{ 12345678|format_number({grouping_size:3, secondary_grouping_size:2}, locale: 'en') }}

* ``rounding_mode``:
* ``rounding_increment``: Control rounding behavior, here is a list of all rounding_mode available:
    * ``ceil``: Ceiling rounding
    * ``floor``: Floor rounding
    * ``down``: Rounding towards zero
    * ``up``: Rounding away from zero
    * ``halfeven``: Round halves to the nearest even integer
    * ``halfup``: Round halves up
    * ``halfdown``: Round halves down

    .. code-block:: twig

        {# 123.5 #}
        {{ 123.456|format_number({rounding_mode:'ceiling', rounding_increment:0.05}, locale: 'en') }}

* ``format_width``:
* ``padding_position``: Set width and padding for the formatted number, here is a list of all padding_position available:
    * ``before_prefix``: Pad before the currency symbol
    * ``after_prefix``: Pad after the currency symbol
    * ``before_suffix``: Pad before the suffix (currency symbol)
    * ``after_suffix``: Pad after the suffix (currency symbol)

    .. code-block:: twig

        {# 123 #}
        {{ 123|format_number({format_width:10, padding_position:'before_suffix'}, locale: 'en') }}

* ``significant_digits_used``:
* ``min_significant_digits_used``:
* ``max_significant_digits_used``: Control significant digits in formatting::

        {# 123.4568 #}
        {{ 123.456789|format_number({significant_digits_used:true, min_significant_digits_used:4, max_significant_digits_used:7}, locale: 'en') }}

* ``lenient_parse``: If true, allows lenient parsing of the input::

        {# 123 #}
        {{ 123|format_number({lenient_parse:true}, locale: 'en') }}

Besides plain numbers, the filter can also format numbers in various styles::

    {# 1,234% #}
    {{ '12.345'|format_number(style: 'percent') }}

    {# twelve point three four five #}
    {{ '12.345'|format_number(style: 'spellout') }}

    {# 12 sec. #}
    {{ '12'|format_duration_number }}

The list of supported styles:

* ``decimal``::

        {# 1,234.568 #}
        {{ 1234.56789 | format_number(style: 'decimal', locale: 'en') }}

* ``currency``::

        {# $1,234.56 #}
        {{ 1234.56 | format_number(style: 'currency', locale: 'en') }}

* ``percent``::

        {# 12% #}
        {{ 0.1234 | format_number(style: 'percent', locale: 'en') }}

* ``scientific``::

        {# 1.23456789e+3 #}
        {{ 1234.56789 | format_number(style: 'scientific', locale: 'en') }}

* ``spellout``::

        {# one thousand two hundred thirty-four point five six seven eight nine #}
        {{ 1234.56789 | format_number(style: 'spellout', locale: 'en') }}

* ``ordinal``::

        {# 1st #}
        {{ 1 | format_number(style: 'ordinal', locale: 'en') }}

* ``duration``::

        {# 2:30:00 #}
        {{ 9000 | format_number(style: 'duration', locale: 'en') }}

As a shortcut, you can use the ``format_*_number`` filters by replacing ``*``
with a style::

    {# 1,234% #}
    {{ '12.345'|format_percent_number }}

    {# twelve point three four five #}
    {{ '12.345'|format_spellout_number }}

You can pass attributes to tweak the output::

    {# 12.3% #}
    {{ '0.12345'|format_percent_number({rounding_mode: 'floor', fraction_digit: 1}) }}

By default, the filter uses the current locale. You can pass it explicitly::

    {# 12,345 #}
    {{ '12.345'|format_number(locale: 'fr') }}

.. _format-number-defaults:

Configuring Application-Wide Defaults
-------------------------------------

When your application needs the same number settings in every template, create a
``NumberFormatter`` and pass it as the second argument to ``IntlExtension``::

    // config/twig.php
    use Twig\Environment;
    use Twig\Extra\Intl\IntlExtension;
    use Twig\Loader\ArrayLoader;

    require_once dirname(__DIR__).'/vendor/autoload.php';

    \Locale::setDefault('en');

    $formatter = new \NumberFormatter('en', \NumberFormatter::DECIMAL);
    $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);
    $formatter->setTextAttribute(\NumberFormatter::POSITIVE_SUFFIX, ' points');
    $formatter->setTextAttribute(\NumberFormatter::NEGATIVE_SUFFIX, ' points');
    $formatter->setSymbol(\NumberFormatter::MINUS_SIGN_SYMBOL, '−');

    $twig = new Environment(new ArrayLoader([
        'dashboard' => '{{ score|format_number }}',
    ]));
    $twig->addExtension(new IntlExtension(null, $formatter));

    echo $twig->render('dashboard', ['score' => -1234.5]);
    // −1,234.50 points

The configured formatter supplies defaults to ``format_number``, every
``format_*_number`` shortcut and ``format_currency``. Twig uses the current
values of:

* every number attribute supported by the ``attrs`` argument and listed above;
* every text attribute available through
  ``NumberFormatter::setTextAttribute()``;
* every symbol available through ``NumberFormatter::setSymbol()``.

This includes values initialized by the formatter's locale and style, not only
values your application changed explicitly. An ``attrs`` entry passed to a
filter overrides the matching number attribute. Text attributes and symbols
cannot be overridden by filter arguments, so their configured values remain in
effect.

The locale and style used to create the configured formatter do not become the
locale and style defaults for filter calls. A call without ``locale`` still uses
``Locale::getDefault()``. ``format_number`` still defaults to the ``decimal``
style, each ``format_*_number`` shortcut selects its named style and
``format_currency`` selects the ``currency`` style. Passing an explicit locale
or style does not discard the configured attributes, text attributes or symbols.
As a result, their prefixes, suffixes and symbols can also affect calls that
select another locale or style.

.. note::

    The ``format_number`` filter is part of the ``IntlExtension`` which is not
    installed by default. Install it first:

    .. code-block:: sh

        $ composer require twig/intl-extra

    Then, on Symfony projects, install the ``twig/extra-bundle``:

    .. code-block:: sh

        $ composer require twig/extra-bundle

    Otherwise, add the extension explicitly on the Twig environment::

        use Twig\Extra\Intl\IntlExtension;

        $twig = new \Twig\Environment(...);
        $twig->addExtension(new IntlExtension());

Arguments
---------

* ``locale``: The locale code as defined in `RFC 5646`_
* ``attrs``: A map of attributes
* ``style``: The style of the number output

.. _RFC 5646: https://www.rfc-editor.org/info/rfc5646
