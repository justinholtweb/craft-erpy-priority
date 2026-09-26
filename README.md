# Erpy for Priority

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for Priority Software.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-priority
php craft plugin/install erpy-priority
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

HTTP Basic, against a Priority user permitted to use the REST API — Priority controls that per user.

### Forms, not resources

Priority exposes its screens: LOGPART, CUSTOMERS, ORDERS, and sub-forms reached with `$expand`. The names here are the ones a Priority consultant will recognise, and every one is a setting because Priority installations are customised by definition.

### No dependable modified column

Most forms do not expose one, so product, stock and customer syncs read everything each time. Erpy’s content hashing means an unchanged record costs a comparison rather than an element save.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/priority, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for Priority is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.
