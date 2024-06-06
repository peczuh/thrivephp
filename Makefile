all: docs

docs:
	./doc/build/apigen -c ./doc/build/apigen-conf.neon
