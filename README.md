<!-- markdownlint-disable no-inline-html -->
<p align="center">
    <br><br>
    <img src="https://leafphp.dev/logo-circle.png" height="100"/>
    <br><br>
</p>

# Alchemy PHP

[![Latest Stable Version](https://poser.pugx.org/leafs/alchemy/v/stable)](https://packagist.org/packages/leafs/alchemy)
[![Total Downloads](https://poser.pugx.org/leafs/alchemy/downloads)](https://packagist.org/packages/leafs/alchemy)
[![License](https://poser.pugx.org/leafs/alchemy/license)](https://packagist.org/packages/leafs/alchemy)

Alchemy is a modern and delightful PHP testing library focused on simplicity and developer experience. We believe that testing should not be a pain: you should focus on building your app instead.

Alchemy is heavily inspired by [Facebook's Jest framework](https://jestjs.io/) and uses some concepts from [Docker](https://www.docker.com/) to provide the easiest and simplest tests known to PHP.

## 📦 Installation

You can quickly install alchemy with composer

```sh
leaf install alchemy
```

Or with composer

```sh
composer require leafs/alchemy
```

## 🗂 Getting Started

By default, alchemy will look for a `test` or `tests` folder in the root of your project and will run all files in the directory that end with `.test.php`. If both of these directories exist, alchemy will use only the `tests` directory.

Also, Alchemy allows you to group tests under `feature` and `unit` directories which run feature and unit tests respectively. If a test is written outside of these directories, it is automatically treated as a unit test.

All of these coupled with other features means that you can install Alchemy and start writing your tests immediately without doing any configuration.

## 👨🏾‍💻 Writing tests

After installing Alchemy, create a `tests` directory and add a new file, eg: `example.test.php`

```php
test('n is null', function () {
  $n = null;
  expect($n)->toBeNull();
});
```

> Note that Alchemy will only run files with the `.test.php` extension. This is to give more room for setup files and more.

## 💬 Stay In Touch

- [Twitter](https://twitter.com/leafphp)
- [Join the forum](https://github.com/leafsphp/leaf/discussions/37)
- [Chat on discord](https://discord.com/invite/Pkrm9NJPE3)

## 📓 Learning Leaf 3

- Leaf has a very easy to understand [documentation](https://leafphp.dev) which contains information on all operations in Leaf.
- You can also check out our [youtube channel](https://www.youtube.com/channel/UCllE-GsYy10RkxBUK0HIffw) which has video tutorials on different topics
- We are also working on codelabs which will bring hands-on tutorials you can follow and contribute to.

## 😇 Contributing

We are glad to have you. All contributions are welcome! To get started, familiarize yourself with our [contribution guide](https://leafphp.dev/community/contributing.html) and you'll be ready to make your first pull request 🚀.

To report a security vulnerability, you can reach out to [@mychidarko](https://twitter.com/mychidarko) or [@leafphp](https://twitter.com/leafphp) on twitter. We will coordinate the fix and eventually commit the solution in this project.

## 🤩 Sponsoring Leaf

Your cash contributions go a long way to help us make Leaf even better for you. You can sponsor Leaf and any of our packages on [open collective](https://opencollective.com/leaf) or check the [contribution page](https://leafphp.dev/support/) for a list of ways to contribute.

And to all our existing cash/code contributors, we love you all ❤️

<a href="https://github.com/leafsphp/leaf/graphs/contributors" target="_blank"><img src="https://opencollective.com/leafphp/contributors.svg?width=890" /></a>

## 🤯 Links/Projects

- [Leaf Docs](https://leafphp.dev)
- [Leaf MVC](https://mvc.leafphp.dev)
- [Leaf API](https://api.leafphp.dev)
- [Leaf CLI](https://cli.leafphp.dev)
- [Aloe CLI](https://leafphp.dev/aloe-cli/)
