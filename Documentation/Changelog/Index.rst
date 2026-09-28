.. include:: ../Includes.txt

.. _changelog:

==========
Change log
==========

Version 7.1.0
-------------

* Added TYPO3 v14 compatibility
* Migrated context menu JavaScript to an ES6 module (RequireJS/AMD removed)
* Migrated module rendering to the ``ModuleTemplate`` API (``assignMultiple()`` + ``renderResponse()``);
  replaced the removed Fluid ``StandaloneView`` / ``ModuleTemplate::setContent()`` workflow
* Replaced removed ``Icon::SIZE_*`` constant with the ``IconSize`` enum (cross-version guarded)
* Removed context-sensitive help button (``ButtonBar::makeHelpButton()``, removed in TYPO3 v13)
* Surface DataHandler errors in the module instead of failing silently
* Removed obsolete context menu registration via ``$GLOBALS`` (auto-registration via ``Services.yaml``)
* Removed unused BackendControllerHook

Version 7.0.0
-------------

* **Only** TYPO3 v13 is supported


Version 6.0.1
-------------

* Removed result display to avoid PHP warning from core (unmaintained class)


Version 6.0.0
-------------

* Drop support for TYPO3 v10
* Require PHP 8.1+


Version 5.0.3
-------------

* Fix v11 compatibility - Thanks to Susi Moog


Version 5.0.2
-------------

* Added requirement on Core to enable docs rendering


Version 5.0.1
-------------

* Fixed settings for documentation rendering


Version 5.0.0
-------------

* First version with new documentation.
* Dropped TYPO3 v9 compatibility.
* Added TYPO3 v11 compatibility (alpha-state).
