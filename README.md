# Binder

## Deprecation Notice

This project is no longer being developed or maintained.

![Binder logo](/images/binder-logo.png)


Binder is an open source digital repository management application, designed
to meet the needs and complex digital preservation requirements of museum
collections. Binder was created by
<a href="http://www.artefactual.com">Artefactual Systems</a> and the
<a href="http://moma.org">Museum of Modern Art</a>.

Binder aims to facilitate digital collections care, management, and
preservation for time-based media and born-digital artworks and is built
from integrating functionality of the
[Archivematica](https://ww.archivematica.org) and
[AtoM](https://www.accesstomemory.org) projects.

A presentation on Binder's functionality (Binder was formerly known as the
DRMC during development) can be found here:

* https://www.youtube.com/watch?v=HPebm5nh83o.

Slides from a presentation at Code4LibBC 2014, including screenshots from the
application, can be found here:

* http://www.slideshare.net/accesstomemory/introducing-the-drmc

**Further resources**

* <a href="http://binder.readthedocs.org/en/latest/">Binder documentation</a>
* <a href="https://groups.google.com/forum/#!forum/binder-repository">Binder User Forum</a>

# Table of contents

* [Installation](#installation)
* [Configuration](#configuration)
* [Contributing](#contributing)
* [Community](#community)
* [Versioning](#versioning)
* [Creators](#creators)
* [Copyright and licenses](#copyright)


## Installation

**IMPORTANT**

At this time, Binder is **not** ready for use in a production environment, and
still requires further developement for the code to function in a development
environment.

We have added further notes about the current status of the project to our
documentation, here:

* http://binder.readthedocs.org/en/latest/user-manual/overview/project-status.html

We have created some installation instructions using
[Vagrant](https://www.vagrantup.com/), so that developers can work with the
code. Note that this will **not** lead to a functioning installation at
present - but we hope that community developers might help us tackle some of
the isues outlined by our developers as part of the installation notes. See
them here:

* https://gist.github.com/sevein/e0b1d036721435add3cd

## Configuration

**Project documentation:**

* http://binder.readthedocs.org/en/latest/

### Storage service client configuration

The Archivematica storage service handles storage of AIPs. Binder interfaces
with it to allow the downloading of AIPs/AIP files and the recovery of AIPs.

To interface with the Archivematica Storage Service, define these environment
variables (e.g. in your PHP pool):

    env[ARCHIVEMATICA_SS_HOST] = "127.0.0.1"
    env[ARCHIVEMATICA_SS_PORT] = "8000"
    env[ARCHIVEMATICA_SS_PIPELINE_UUID] = "6117c5fa-d63f-44d8-9920-89468c68683e"
    env[ARCHIVEMATICA_SS_USER] = "foo"
    env[ARCHIVEMATICA_SS_API_KEY] = "bar"

The host and port will default to "127.0.0.1" and "8000" respectively, but the
pipeline UUID, the user and the api key are mandatory and required for both, the
CLI and web environments.

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
      ldap_user_group: CN=AtoM users,OU=Archivists,OU=Groups,DC=EXAMPLE,DC=COM

Finally clear the Symfony cache and restart your pool.

NOTE: This application will check if existing LDAP users are member of the group
defined in ldap_user_group.

### Storage service AIP recovery process and configuration

AIP recovery allows a Binder administrator to replace a corrupt version
of a stored AIP with a correct version (restored from a backup, for example).

The AIP recovery process involves copying the recovered version of the AIP
into a dedicated recovery directory accessible by the storage service. You can
determine the location of this directory by clicking "Locations" in the storage
service administration web interface and finding the path assocated with AIP
recovery.

The Binder's AIP recovery integration requires the storage service be configured
to report back to Binder when a storage service administrator has made a
decision about an AIP restore request (approving or rejecting it) or if an
approved AIP restore request has failed.

To configure the storage service to report AIP restore progress back to
Binder, click "Administration" in the storage service administration web
interface enter the following into the field labelled "Recover request
notification url" (replacing the Binder server address placeholder with your
own Binder server's address):

  http://<Binder server address>/api/recover/results

For authentication purposes, you'll also need to enter a valid Binder username
and password into the two fields below it. Click "Save" when you're done.

## Contributing

Please read through our <a href="https://github.com/artefactual/binder/blob/master/CONTRIBUTING">contributing guidelines</a>.
Included are directions for opening issues, coding standards, and notes on
development.

Editor preferences are available in the <a href="https://github.com/artefactual/binder/blob/master/.editorconfig">editor config</a>
for easy use in common text editors. Read more and download plugins at
http://editorconfig.org.

Binder was built on [Access to Memory](https://www.accesstomemory.org) (AtoM)
which is an application built using the Symfony framework.

See <a href='http://symfony-project.com'>http://symfony-project.com</a> for additional instructions on installing and<br />configuring a Symfony application.


## Community

Keep track of development and community news.

* <a href="http://binder.readthedocs.org/en/latest/">Binder documentation</a>
* <a href="https://groups.google.com/forum/#!forum/binder-repository">Binder User Forum</a>
* Follow [@accesstomemory](https://twitter.com/accesstomemory) on Twitter.
* Chat with us in IRC. On the [OFTC network](http://www.oftc.net), in the #openarchives
  channel.


## Versioning

For transparency into our release cycle and in striving to maintain backward
compatibility, Binder is maintained under the [Semantic Versioning guidelines](http://www.semver.org).

Sometimes we screw up, but we'll adhere to those rules whenever possible.

## Creators

* [Artefactual Systems Inc](http://www.artefactual.com)
* MoMA - [The Museum of Modern Art](http://moma.org)


## Copyright and license

Code and documentation copyright Artefactual Systems Inc. Code released under
the AGPLv3 license. Docs released under Creative Commons.
