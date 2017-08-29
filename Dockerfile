FROM pixnet/nginx-php7-fpm:latest
ENV VIPS_VERSION 8.5.6
RUN apt-get update
RUN DEBIAN_FRONTEND=noninteractive apt-get install --no-install-recommends --no-install-suggests -y \
	build-essential \
	pkg-config \
	glib2.0-dev \
	libexpat1-dev \
	php7.0-dev \
	php-pear \
	libtiff5-dev \
	libjpeg62-turbo-dev \
	libexif-dev \
	libgsf-1-dev \
	libpng12-dev \
	libfftw3-dev \
	libwebp-dev \
	liborc-0.4-dev
RUN cd /tmp ; curl -s -o vips.tar.gz -L https://github.com/jcupitt/libvips/releases/download/v${VIPS_VERSION}/vips-${VIPS_VERSION}.tar.gz ; tar zxf vips.tar.gz
RUN cd /tmp/vips-${VIPS_VERSION} ; ./configure ; make all ; make install ; make clean
RUN pecl channel-update pecl.php.net
RUN yes | pecl install vips
RUN echo "extension=vips.so" >> /etc/php/7.0/mods-available/vips.ini
RUN phpenmod vips
COPY conf/nginx.conf /etc/nginx/conf.d/default.conf
RUN mkdir -p /pixnet/imageproxy.pimg.tw
COPY imageproxy.pimg.tw/ /pixnet/imageproxy.pimg.tw/
RUN cd /pixnet/imageproxy.pimg.tw/ ; composer update
WORKDIR /pixnet/imageproxy.pimg.tw/
COPY conf/www.conf /etc/php/7.0/fpm/pool.d/
