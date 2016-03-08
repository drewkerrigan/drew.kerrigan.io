#!/bin/bash

ghc --make site.hs
./site rebuild
cp -R _site/* ../drew.kerrigan.io_site/
./site watch
