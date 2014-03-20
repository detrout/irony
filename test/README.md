Unit Tests for iRony CalDAV/CardDAV/WebDAV Server
=================================================

The tests are based on the [CalDAVTester][1] suite and require the Python-based
suite to be installed and set up before.


Installation
------------

Download and configure the CalDAVTester suite according to the descroptions
in the [wiki][1]. Install it anywhere on your system.

Make sure the pxcalendar package is installed. Do this by running

  $ cd <path-to-caldavtester-directory>
  $ ./run.py -s



Configure Tests
---------------

The settings for the Kolab server to test are saved in `serverinfo.xml`
located in this `test` folder.

Adjust the following properties in your local `serverinfo.xml` file:

* serverinfo.host

as well as the values of the following substitution keys:

* $root:
* $userid%d:
* $username%d:
* $username-encoded%d:
* $firstname%d:
* $lastname%d:
* $pswd%d:

Make sure that two users matching the patterns of $userid%d and subsequent do
exist on the Kolab server that is to be tested with. Default user accounts
are set to dav.user01@example.org and dav.user02@example.org with password "12345".


Running the Tests
-----------------

This `test` directory contains a helper script `runtests.sh` that runs all
tests with the CalDAVTester suite. Run it as follows:

  $ cd <iRony-directory>/test
  $ ./runtests.sh <path-to-caldavtester-directory>


[1]: http://trac.calendarserver.org/wiki/CalDAVTester
