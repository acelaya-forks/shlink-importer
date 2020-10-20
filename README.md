# Shlink importer

Collection of tools to import links from different sources and map them to a shlink-compliant format.

[![Build Status](https://img.shields.io/travis/shlinkio/shlink-importer.svg?style=flat-square)](https://travis-ci.org/shlinkio/shlink-importer)
[![Code Coverage](https://img.shields.io/scrutinizer/coverage/g/shlinkio/shlink-importer.svg?style=flat-square)](https://scrutinizer-ci.com/g/shlinkio/shlink-importer/)
[![Scrutinizer Code Quality](https://img.shields.io/scrutinizer/g/shlinkio/shlink-importer.svg?style=flat-square)](https://scrutinizer-ci.com/g/shlinkio/shlink-importer/)
[![Latest Stable Version](https://img.shields.io/github/release/shlinkio/shlink-importer.svg?style=flat-square)](https://packagist.org/packages/shlinkio/shlink-importer)
[![License](https://img.shields.io/github/license/shlinkio/shlink-importer.svg?style=flat-square)](https://github.com/shlinkio/shlink-importer/blob/main/LICENSE)
[![Paypal donate](https://img.shields.io/badge/Donate-paypal-blue.svg?style=flat-square&logo=paypal&colorA=aaaaaa)](https://slnk.to/donate)

## Installation

This module can be installed using composer:

    composer require shlinkio/shlink-importer

## Supported import sources

* Bit.ly

## Usage

The module register the `short-urls:import` command, which can be used to import links from different sources.

This command requires the source from which to import to be provided:

    `bin/cli short-urls:import bitly`

The command will ask you some questions about how to import from this source, and then, once the data is there, it will invoke the `Shlinkio\Shlink\Importer\ImportedLinksProcessorInterface` service.

## Requirements

This package expects some services to be registered as dependencies, as they need to be used by some of the tools.

* `Shlinkio\Shlink\Importer\ImportedLinksProcessorInterface`: It has to resolve an object implementing the interface.
* `Psr\Http\Client\ClientInterface`: Required to be able to import from Bit.ly
* `Psr\Http\Message\RequestFactoryInterface`: Required to be able to import from Bit.ly
