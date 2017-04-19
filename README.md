## Pre-installation

You will need the following program installed in your computer:

- [composer](https://getcomposer.org/download/)


## Installation

Setup your environment:
```
cp .env.example .env
```
Change the values you need in the `.env` file.
Start the server:
```
php -S localhost:8000 -t web
```
Try it! [http://localhost:8000](http://localhost:8000)

## Configuration

When you are deploying, there are a few configuration you might want to change. Here are the available configurations:

**ENVIRONMENT**: Can be _development_, _staging_, _testing_ or _production_ (default: _development_)
