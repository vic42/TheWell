#include <wiringPi.h>
#include <errno.h>
#include <error.h>
#include <stdio.h>
#include <stdint.h>
#include <stdlib.h>
#include <math.h>

#define	TRIG_PIN	27
#define	ECHO_PIN	22
#define	NUM_AVG		13		/* number of pings to average */
#define	MINVALID	(NUM_AVG / 3)	/* minimum number valid echoes to accept range */
#define MAXRANGE	(200 * 2)	/* maximum expected sound travel distance in centimeters */
#define MINRANGE	( 10 * 2)	/* minimum expected sound travel distance in centimeters */
#define isvalid(x)	( x > MINRANGE && x < MAXRANGE )

uint32_t	time1, time2, time_diff;
volatile int	flag;

void isr(void)		/* interrupt service routine */
{
	uint32_t time_temp=micros();
	if(!flag) {
		time1=time_temp;
		flag=1;
	}
	else {
		time2=time_temp;
		flag=0;
	}
}

int main(int argc, char *argv[])	/* main ultrasonic ranger function */
{
	int i,j, valid;
	float temperature, distance, dist[NUM_AVG], avg_deviation, dist_average;
	
	/* get temperature value from command line */
	if (argc < 2) error(1,0,"usage: %s temperature in °C", argv[0]);
	temperature = strtof(argv[1], NULL);
	if (errno) error(1,errno,"temperature value conversion failed");

	/* setup wiringPi library and pin modes*/
	if(wiringPiSetupGpio() != 0) error(1,0,"wiringPiSetup failed");
	pinMode(TRIG_PIN, OUTPUT);
	pinMode(ECHO_PIN, INPUT);
	pullUpDnControl(ECHO_PIN, PUD_OFF);
	digitalWrite(TRIG_PIN, 0);
	
	/* install interrupt service routine */
	if(wiringPiISR(ECHO_PIN, INT_EDGE_BOTH,&isr) < 0)
		error( 1, errno, "isr installation failed");

	dist_average = avg_deviation = distance = 0.0;
	
	/* now do the sensor reading NUM_AVG times*/
	for (i=0, valid=0; i < NUM_AVG; ++i) {
		time1 = time2 = flag = 0;
		digitalWrite(TRIG_PIN,1);
		delayMicroseconds(10);
		digitalWrite(TRIG_PIN,0);
		delayMicroseconds(25000);
		time_diff=time2-time1;
		dist[valid] = time_diff * (331.5 + temperature * 0.595) / 20000;
		if ( isvalid(dist[valid]) ) {
			dist_average = (valid == 0) ? dist[valid] : (dist_average * valid + dist[valid]) / (valid + 1);
			++valid;
		}
	}
	
	/* check if we have enough valid values */
	if (valid < MINVALID) error(2,0,"not enough valid pings (%d < %d)", valid, MINVALID);
	
	/* calculate average deviation */
	for (i=0; i < valid; ++i) avg_deviation = ( avg_deviation * i + fabs(dist[i]-dist_average) ) / (i+1);

	/* use only values vith deviation smaller than average */
	for (i=0, j=0; i < valid; ++i) {
		if ( fabs(dist[i]-dist_average) < avg_deviation ) {
			distance = (j==0) ? dist[i] : (distance * j + dist[i]) / (j+1);
			++j;
		}
	}
	
	fprintf(stdout,"%.2f cm\n%d of %d values out of %d pings\navg_dev = %.2f\n", distance, j+1, valid + 1, NUM_AVG, avg_deviation);
	return 0;
}
