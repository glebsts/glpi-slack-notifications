FROM scratch
COPY . /glpi-gsn-plugin
ENTRYPOINT echo "Don't run this container, it is just a file storage"
