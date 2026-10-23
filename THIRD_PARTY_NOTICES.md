# Third-party notices

Archive File's original code is provided under the MIT license in LICENSE.txt.

The test fixtures `tests/fixtures/modx/transport-reference.php`, `xpdovehicle.class.php`
and `xpdoscriptvehicle.class.php` contain xPDO code, copyright 2010–2015 MODX, LLC,
under GNU GPL version 2 or later. Their original copyright/license headers are retained.
The GPL v2 text is in `licenses/GPL-2.0.txt`. These fixtures are included as source for
installer simulation; they are not copied to a MODX installation by the package.
`transport-reference.php` is a reference/test subset, not an independent upstream release.

The UI tests use jsdom 26.1.0 (MIT), installed separately through npm. jsdom and its
transitive dependencies are not bundled in the transport package. Their own license
notices apply to installations of those dependencies.
