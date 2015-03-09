# Binder

Binder is an open source digital repository designed to meet the needs and
complex digital preservation requirements of museum collections. Binder was
created by Artefactual Systems and the Museum of Modern Art.

Binder aims to facilitate digital collections care, management, and
preservation for time-based media and born-digital artworks and is built
from integrating functionality of the Archivematica and AtoM projects.

A presentation on Binder's functionality (Binder was formerly known as the
DRMC during development) can be found here:

https://www.youtube.com/watch?v=HPebm5nh83o.

# Table of contents

* [Installation](#installation)
* [Configuration](#configuration)
* [Contributing](#contributing)
* [Community](#community)
* [Versioning](#versioning)
* [Creators](#creators)
* [Copyright and licenses](#copyright)

## Installation

See http://accesstomemory.org/wiki for instructions on how to perform the
basic installation.

You'll also need to install the Binder interface. To do so on Ubuntu (tested
on Ubuntu 14.04), do the following:

```shell
# Install Node.js
sudo add-apt-repository ppa:chris-lea/node.js
sudo apt-get update
sudo apt-get install nodejs

# Install Grunt
sudo npm install -g grunt-cli

# Install packages based on package.json:
npm install
```

## Configuration

### Storage service client configuration

The Archivematica storage service handles storage of AIPs. Binder interfaces
with it to allow the downloading of AIPs/AIP files and the recovery of AIPs.

To interface with the Archivematica Storage Service, define these environment
variables (e.g. in your PHP pool):

    env[ARCHIVEMATICA_SS_HOST] = "127.0.0.1"
    env[ARCHIVEMATICA_SS_PORT] = "8000"
    env[ARCHIVEMATICA_SS_PIPELINE_UUID] = "6117c5fa-d63f-44d8-9920-89468c68683e"

### LDAP configuration

Use of LDAP authentication requires installing php5-ldap and making sure that
the module is being loaded.

You'll also need to define the following environment variables (e.g. in your
PHP pool):

    env[ATOM_DRMC_LDAP_ADMIN_USERNAME] = "foo"
    env[ATOM_DRMC_LDAP_ADMIN_PASSWORD] = "bar"

Next create apps/qubit/config/factories.yml if it doesn't exist yet (this file
is not tracked by git) with the following contents:

    all:
      user:
        class: adLdapUser

Also create apps/qubit/config/app.yml if it doesn't exist yet (this file is
not tracked by git) with the following contents:

    all:
      ldap_account_suffix: "@example.com"
      ldap_base_dn: DC=EXAMPLE,DC=COM
      ldap_domain_controllers: ad01.example.com
      ldap_user_group: CN=Binder users,OU=Archivists,OU=Groups,DC=EXAMPLE,DC=COM

Finally clear the Symfony cache and restart your pool.

NOTE: This application will check if existing LDAP users are member of the group
defined in ldap_user_group.


## Contributing

Please read through our <a href="https://github.com/artefactual/drmc/blob/master/CONTRIBUTING.md">contributing guidelines</a>.
Included are directions for opening issues, coding standards, and notes on
development.

Editor preferences are available in the <a href="https://github.com/artefactual/drmc/blob/master/.editorconfig">editor config</a>
for easy use in common text editors. Read more and download plugins at<br /><a href="http://editorconfig.org"><a href='http://editorconfig.org'>http://editorconfig.org</a></a>.

Binder was built on Access to Memory (AtoM) which is an application built
using the Symfony framework.

See <a href='http://symfony-project.com'>http://symfony-project.com</a> for additional instructions on installing and<br />configuring a Symfony application.

## Community

Keep track of development and community news.

* Follow [@accesstomemory](https://twitter.com/accesstomemory) on Twitter.
* Chat with us in IRC. On the [OFTC network](http://www.oftc.net), in the #openarchives
  channel.


## Versioning

For transparency into our release cycle and in striving to maintain backward
compatibility, Binder is maintained under the [Semantic Versioning guidelines](http://www.semver.org).
Sometimes we screw up, but we'll adhere to those rules whenever possible.


## Creators

* Artefactual Systems Inc
* MoMA - The Museum of Modern Art


## Copyright and license

Code and documentation copyright Artefactual Systems Inc. Code released under
the AGPLv3 license. Docs released under Creative Commons.
