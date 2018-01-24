Binder: Digital Repository for Museum Collections
==================================================

The Binder frontend is built with AngularJS. Interaction with AtoM is through a
REST API.


Installation of frontend
------------------------

Install system dependencies (tested in Ubuntu 12.04)::

  $ sudo add-apt-repository ppa:chris-lea/node.js
  $ sudo apt-get update
  $ sudo apt-get install nodejs build-essential # Yep, you're going to need make, gcc, etc...
  $ sudo npm install -g grunt-cli

Install JavaScript dependencies (from /plugins/arDrmcPlugin/frontend)::

  $ npm install
  $ grunt build

Clear the symfony cache (from the AtoM root directory)::

  $ php symfony cc

You can run "grunt watch" to detect changes during development and trigger
the build. Take into account that "grunt build" will be still necessary to
be executed once when you are configuring a new environment or the vendor
browserify build has changed. See Gruntfile.js for more details.
