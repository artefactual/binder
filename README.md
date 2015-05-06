# Binder

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

We will be adding configuration notes to the project documentation
soon. In the meantime, if you need to configure Archivematica's storage
service so that Binder can interface with it, make sure to define the
following environment variables, depending on your installation (the
following values are just examples):

    env[ARCHIVEMATICA_SS_HOST] = "127.0.0.1"
    env[ARCHIVEMATICA_SS_PORT] = "8000"
    env[ARCHIVEMATICA_SS_PIPELINE_UUID] = "6117c5fa-d63f-44d8-9920-89468c68683e"

**Project documentation:**

* http://binder.readthedocs.org/en/latest/

## Contributing

Please read through our <a href="https://github.com/artefactual/binder/blob/master/CONTRIBUTING.md">contributing guidelines</a>.
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
