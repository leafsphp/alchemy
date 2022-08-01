<!-- markdownlint-disable no-inline-html -->
<p align="center">
    <br><br>
    <img src="https://leafphp.dev/logo-circle.png" height="100"/>
    <br><br>
</p>

<h1 align="center">Alchemy</h1>

[![Latest Stable Version](https://poser.pugx.org/leafs/alchemy/v/stable)](https://packagist.org/packages/leafs/alchemy)
[![Total Downloads](https://poser.pugx.org/leafs/alchemy/downloads)](https://packagist.org/packages/leafs/alchemy)
[![License](https://poser.pugx.org/leafs/alchemy/license)](https://packagist.org/packages/leafs/alchemy)

Alchemy is a Leaf module which allows you to quickly and efficiently create and run tests in your Leaf apps. Leaf 3 is built with testing in mind. In fact, support for testing with Pest PHP/PHPUnit is included out of the box. However, Alchemy allows you to avoid all the hustle and write your tests without having to setup anything. Just run the setup script and run your tests without any config or anything like that.

## 📦 Installation

You can quickly install alchemy with leaf CLI

```sh
leaf install alchemy
```

Or with composer

```sh
composer require leafs/alchemy
```

## 🗂 Your First Test

After installing Alchemy, simply run the setup script

```sh
./vendor/bin/alchemy setup
```

This uses Pest PHP by default. If you want to use PHPUnit, you can add the `--phpunit` option to the setup script.

```sh
./vendor/bin/alchemy setup --phpunit
```

This will setup dummy tests and an `alchemy.config.php` file which you can use to gain a little more control over your tests. After writing the tests you need to write, you can simply run the test script.

```sh
./vendor/bin/alchemy run
```

Based on your engine, you might see either of the outputs below

- PEST PHP

<img width="307" alt="image" src="https://user-images.githubusercontent.com/26604242/182198978-1b8e2ba2-42e7-4345-82d0-3ae5be35d299.png">

- PHPUnit

<img width="770" alt="image" src="https://user-images.githubusercontent.com/26604242/182198446-47a4a581-3aa4-470c-b450-420604b9bb6c.png">

> Alchemy is a test runner, not a testing framework.

### Commands

<img width="723" alt="image" src="https://user-images.githubusercontent.com/26604242/182193129-b76bfcda-c74e-4458-801b-650939ed2f5f.png">

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

### Code contributors

<table>
	<tr>
		<td align="center">
			<a href="https://github.com/mychidarko">
				<img src="https://avatars.githubusercontent.com/u/26604242?v=4" width="120px" alt=""/>
				<br />
				<sub>
					<b>Michael Darko</b>
				</sub>
			</a>
		</td>
	</tr>
</table>

## 🤩 Sponsoring Leaf

Your cash contributions go a long way to help us make Leaf even better for you. You can sponsor Leaf and any of our packages on [open collective](https://opencollective.com/leaf) or check the [contribution page](https://leafphp.dev/support/) for a list of ways to contribute.

And to all our existing cash/code contributors, we love you all ❤️

### Cash contributors

<table>
	<tr>
		<td align="center">
			<a href="https://opencollective.com/aaron-smith3">
				<img src="https://images.opencollective.com/aaron-smith3/08ee620/avatar/256.png" width="120px" alt=""/>
				<br />
				<sub><b>Aaron Smith</b></sub>
			</a>
		</td>
		<td align="center">
			<a href="https://opencollective.com/peter-bogner">
				<img src="https://images.opencollective.com/peter-bogner/avatar/256.png" width="120px" alt=""/>
				<br />
				<sub><b>Peter Bogner</b></sub>
			</a>
		</td>
		<td align="center">
			<a href="#">
				<img src="https://images.opencollective.com/guest-32634fda/avatar.png" width="120px" alt=""/>
				<br />
				<sub><b>Vano</b></sub>
			</a>
		</td>
	</tr>
</table>

## 🤯 Links/Projects

- [Leaf Docs](https://leafphp.dev)
- [Leaf MVC](https://mvc.leafphp.dev)
- [Leaf API](https://api.leafphp.dev)
- [Leaf CLI](https://cli.leafphp.dev)
- [Aloe CLI](https://leafphp.dev/aloe-cli/)
